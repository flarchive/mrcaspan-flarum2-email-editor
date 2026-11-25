<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\Post\Post;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\Factory;

/**
 * Builds the real notification an email's texts belong to, so the preview can
 * render that notification's own email views, exactly as they send.
 *
 * Which notification: every registered notification type that sends email
 * names its views and its subject text in its source. The type whose views
 * or blueprint class mention one of the email's text keys is the one. A key
 * built at run time ('ext.email.'.$kind.'.subject') is matched too, and the
 * changing part becomes the blueprint's string argument.
 *
 * With what: the newest post guests can see, its discussion and its author.
 * Nothing is saved.
 */
class BlueprintPreview
{
    /** @var array<string, string> file contents by path */
    protected array $sources = [];

    public function __construct(
        protected Container $container,
        protected Factory $views
    ) {
    }

    /**
     * @param string[] $keys the email's text keys
     * @return (MailableInterface&BlueprintInterface)|null
     */
    public function make(array $keys, User $recipient): ?object
    {
        $match = $this->find($keys);

        return $match ? $this->build($match[0], $match[1], $recipient) : null;
    }

    protected function build(string $class, ?string $part, User $recipient): ?object
    {
        $posts = Post::whereVisibleTo(new Guest())
            ->where('type', 'comment')
            ->whereNotNull('user_id')
            ->orderByDesc('id')
            ->limit(2)
            ->get()
            ->all();

        if (! $posts || ! $posts[0]->discussion || ! $posts[0]->user) {
            return null;
        }

        return $this->construct($class, $part, $posts, $recipient);
    }

    /**
     * Any real notification email, for previewing the parts every email shares
     * (greeting, sign-off, footer). A new-post email if there is one, as those
     * have the most in them.
     *
     * @return (MailableInterface&BlueprintInterface)|null
     */
    public function makeAny(User $recipient): ?object
    {
        $classes = $this->mailableClasses();

        usort($classes, fn ($a, $b) => $this->rank($a) <=> $this->rank($b));

        foreach ($classes as $class) {
            if ($blueprint = $this->build($class, null, $recipient)) {
                return $blueprint;
            }
        }

        return null;
    }

    /**
     * Whether any of these keys is used in an email: in a notification or in
     * one of the email layouts. Texts nothing uses can't be previewed.
     */
    public function used(array $keys): bool
    {
        $source = '';

        foreach ($this->mailableClasses() as $class) {
            $source .= $this->source($class);
        }

        foreach ($this->layoutFiles() as $file) {
            $source .= $this->sources[$file] ??= (string) @file_get_contents($file);
        }

        foreach ($keys as $key) {
            if (str_contains($source, "'$key'") || str_contains($source, "\"$key\"") || $this->dynamicPart($key, $source) !== null) {
                return true;
            }
        }

        return false;
    }

    protected function rank(string $class): int
    {
        $type = $class::getType();

        return match (true) {
            $type === 'newPost' => 0,
            $type === 'postCreated' => 1,
            default => 2,
        };
    }

    /**
     * @return class-string[]
     */
    protected function mailableClasses(): array
    {
        return array_values(array_filter(
            array_keys((array) $this->container->make('flarum.notification.blueprints')),
            fn ($class) => is_string($class) && class_exists($class) && is_subclass_of($class, MailableInterface::class)
        ));
    }

    /**
     * Every view file of the email layouts (core's and this extension's).
     *
     * @return string[]
     */
    protected function layoutFiles(): array
    {
        $finder = method_exists($this->views, 'getFinder') ? $this->views->getFinder() : null;
        $hints = $finder && method_exists($finder, 'getHints') ? $finder->getHints() : [];
        $files = [];

        foreach (['mail', 'email-editor', 'email-editor-core'] as $namespace) {
            foreach ((array) ($hints[$namespace] ?? []) as $dir) {
                if (! is_dir($dir)) {
                    continue;
                }

                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if (str_ends_with($file->getFilename(), '.php')) {
                        $files[] = $file->getPathname();
                    }
                }
            }
        }

        return array_unique($files);
    }

    /**
     * @return array{0: class-string, 1: ?string}|null the blueprint class, and the changing part of a run-time key
     */
    protected function find(array $keys): ?array
    {
        $dynamic = null;

        foreach ($this->mailableClasses() as $class) {
            $source = $this->source($class);

            if ($source === '') {
                continue;
            }

            foreach ($keys as $key) {
                if (str_contains($source, "'$key'") || str_contains($source, "\"$key\"")) {
                    return [$class, null];
                }

                if (! $dynamic && ($part = $this->dynamicPart($key, $source)) !== null) {
                    $dynamic = [$class, $part];
                }
            }
        }

        return $dynamic;
    }

    /**
     * The blueprint class's source plus its two email views' sources.
     */
    protected function source(string $class): string
    {
        try {
            $reflection = new \ReflectionClass($class);
            $files = [$reflection->getFileName()];

            if ($reflection->isInstantiable()) {
                $views = $reflection->newInstanceWithoutConstructor()->getEmailViews();
                $finder = method_exists($this->views, 'getFinder') ? $this->views->getFinder() : null;

                foreach ((array) $views as $view) {
                    if ($finder && is_string($view)) {
                        $files[] = $finder->find($view);
                    }
                }
            }
        } catch (\Throwable) {
            // A blueprint that can't be read this way just isn't matched.
            return '';
        }

        $source = '';

        foreach (array_filter($files) as $file) {
            $source .= $this->sources[$file] ??= (string) @file_get_contents($file);
        }

        return $source;
    }

    /**
     * For 'ext.email.thing.subject', look for 'ext.email.'.$x.'.subject' in the
     * source and return "thing".
     */
    protected function dynamicPart(string $key, string $source): ?string
    {
        $parts = explode('.', $key);

        for ($i = 2; $i < count($parts); $i++) {
            $before = preg_quote(implode('.', array_slice($parts, 0, $i)).'.', '/');
            $after = $i < count($parts) - 1 ? preg_quote('.'.implode('.', array_slice($parts, $i + 1)), '/') : '';
            $var = '\$[A-Za-z_][\w\->]*';

            $pattern = $after === ''
                ? "/['\"]{$before}['\"]\s*\.\s*{$var}/"
                : "/['\"]{$before}['\"]\s*\.\s*{$var}\s*\.\s*['\"]{$after}['\"]/";

            if (preg_match($pattern, $source)) {
                return $parts[$i];
            }
        }

        return null;
    }

    /**
     * @param Post[] $posts newest first
     */
    protected function construct(string $class, ?string $part, array $posts, User $recipient): ?object
    {
        $post = $posts[0];
        $author = $post->user;
        $postQueue = $posts;

        try {
            $constructor = (new \ReflectionClass($class))->getConstructor();
            $args = [];

            foreach ($constructor ? $constructor->getParameters() : [] as $param) {
                $type = $param->getType();
                $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;

                if ($typeName && is_a($typeName, Post::class, true)) {
                    $args[] = array_shift($postQueue) ?? $post;
                } elseif ($typeName && is_a($typeName, Discussion::class, true)) {
                    $args[] = $post->discussion;
                } elseif ($typeName && is_a($typeName, User::class, true)) {
                    // "user" is who the email is about (the recipient, e.g. a suspension); others did something.
                    $args[] = $param->getName() === 'user' ? $this->recipient($recipient) : $author;
                } elseif ($typeName && is_a($typeName, Group::class, true)) {
                    $args[] = Group::whereNotIn('id', [Group::GUEST_ID, Group::MEMBER_ID])->orderBy('id')->first() ?? new Group();
                } elseif ($typeName && is_a($typeName, AbstractModel::class, true)) {
                    // Some other record (a flag, say), linked to the sample post.
                    $model = new $typeName();
                    $model->setRelation('post', $post);
                    $model->setRelation('discussion', $post->discussion);
                    $model->setRelation('user', $author);
                    $args[] = $model;
                } elseif ($typeName === 'string' && $part !== null) {
                    $args[] = $part;
                } elseif ($param->isDefaultValueAvailable()) {
                    $args[] = $param->getDefaultValue();
                } elseif ($type?->allowsNull()) {
                    $args[] = null;
                } elseif ($typeName === 'string') {
                    $args[] = '';
                } elseif ($typeName === 'array') {
                    $args[] = [];
                } elseif ($typeName === 'int') {
                    $args[] = 1;
                } else {
                    return null;
                }
            }

            return new $class(...$args);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * An unsaved copy of the admin, with a sample suspension reason so the
     * suspension email shows one.
     */
    protected function recipient(User $recipient): User
    {
        $copy = clone $recipient;

        if (! $copy->suspend_message) {
            $copy->suspend_message = resolve('translator')->trans('mrcaspan-flarum2-email-editor.admin.sample_reason');
        }

        return $copy;
    }
}
