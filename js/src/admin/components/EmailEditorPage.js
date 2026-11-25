import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Component from 'flarum/common/Component';
import Dropdown from 'flarum/common/components/Dropdown';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Switch from 'flarum/common/components/Switch';
import Select from 'flarum/common/components/Select';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import saveSettings from 'flarum/admin/utils/saveSettings';

const PREFIX = 'mrcaspan-flarum2-email-editor.admin.';
const t = (key, params) => app.translator.trans(PREFIX + key, params);
const SETTING = 'mrcaspan-email-editor.';

const api = (path = '') => app.forum.attribute('apiUrl') + '/email-editor' + path;

// Must match LayoutSettings::DEFAULTS in PHP.
const LAYOUT_DEFAULTS = {
  custom_layout: false,
  show_title: true,
  show_greeting: true,
  show_signoff: true,
  logo_align: 'left',
  color_bg: '#ffffff',
  color_card: '#ffffff',
  color_border: '#e1e1e1',
  color_text: '#333333',
  color_accent: '',
  footer_text: '',
};
const COLORS = ['color_bg', 'color_card', 'color_border', 'color_text', 'color_accent'];

// A section heading inside a dropdown menu (rendered as its own <li>).
class MenuHeading extends Component {
  static isListItem = true;

  view() {
    return <li className="Dropdown-header">{this.attrs.label}</li>;
  }
}

const humanize = (s) =>
  String(s)
    .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
    .replace(/[_-]+/g, ' ')
    .replace(/^./, (c) => c.toUpperCase());

export default class EmailEditorPage extends ExtensionPage {
  oninit(vnode) {
    super.oninit(vnode);

    this.tab = 'emails';
    this.data = null;
    this.locale = null;
    this.search = '';
    this.selectedId = null;
    this.drafts = {};
    this.showOriginal = {};
    this.errors = {};
    this.saving = false;
    this.notice = null;

    this.preview = null;
    this.previewTab = 'html';
    this.previewing = false;
    this.sending = false;

    this.layout = this.readLayout();
    this.layoutSaved = { ...this.layout };

    this.load();
  }

  // This extension has no permissions, so drop that section.
  sections(vnode) {
    const items = super.sections(vnode);
    items.remove('permissions');
    return items;
  }

  // ---------------------------------------------------------------- data

  load(locale) {
    const params = locale ? { locale } : {};

    return app.request({ method: 'GET', url: api(), params }).then((res) => {
      this.setData(res);
      m.redraw();
    });
  }

  setData(res) {
    this.data = res;
    this.locale = res.locale;
    this.groups = {};
    res.groups.forEach((g) => (this.groups[g.id] = g));

    if (this.selectedId && !this.groups[this.selectedId]) this.selectedId = null;
  }

  readLayout() {
    const s = app.data.settings;
    const out = {};

    Object.keys(LAYOUT_DEFAULTS).forEach((name) => {
      const def = LAYOUT_DEFAULTS[name];
      const raw = s[SETTING + name];

      if (raw === undefined || raw === null || raw === '') {
        out[name] = def;
      } else if (typeof def === 'boolean') {
        out[name] = raw === true || raw === '1' || raw === 1 || raw === 'true';
      } else {
        out[name] = String(raw);
      }
    });

    return out;
  }

  extensionTitle(namespace) {
    if (namespace === 'core') return extractText(t('core_title'));

    const exts = app.data.extensions || {};
    const title = (ext) => ext?.extra?.['flarum-extension']?.title;

    if (exts[namespace] && title(exts[namespace])) return title(exts[namespace]);

    // Translation namespaces don't always match the extension id
    // ("flarum-calendar" for "vendor-pokerforum-calendar", say): try the part after the vendor.
    const suffix = namespace.split('-').slice(1).join('-');
    if (suffix) {
      const id = Object.keys(exts).find((key) => key.endsWith('-' + suffix));
      if (id && title(exts[id])) return title(exts[id]);
    }

    return humanize(namespace);
  }

  groupLabel(group) {
    return group.label || extractText(t('general_texts'));
  }

  fieldLabel(field) {
    switch (field.role) {
      case 'subject':
        return t('field_subject');
      case 'html':
        return t('field_html');
      case 'plain':
        return t('field_plain');
      case 'body':
        return t('field_body');
      default:
        return humanize(field.field.replace(/\./g, ' '));
    }
  }

  draftValue(field) {
    return Object.prototype.hasOwnProperty.call(this.drafts, field.key) ? this.drafts[field.key] : field.value;
  }

  dirtyKeys(group) {
    if (!group) return [];
    return group.fields.filter((f) => this.draftValue(f) !== f.value).map((f) => f.key);
  }

  layoutDirty() {
    return Object.keys(LAYOUT_DEFAULTS).some((k) => this.layout[k] !== this.layoutSaved[k]);
  }

  // ---------------------------------------------------------------- actions

  select(id) {
    if (id === this.selectedId) return;

    const current = this.groups[this.selectedId];
    if (this.dirtyKeys(current).length && !confirm(extractText(t('discard_confirm')))) return;

    this.selectedId = id;
    this.drafts = {};
    this.errors = {};
    this.showOriginal = {};
    this.preview = null;
    this.notice = null;
  }

  changeLocale(locale) {
    const current = this.groups[this.selectedId];
    if (this.dirtyKeys(current).length && !confirm(extractText(t('discard_confirm')))) return;

    this.drafts = {};
    this.errors = {};
    this.preview = null;
    this.data = null;
    this.load(locale);
  }

  save(group) {
    const values = {};
    this.dirtyKeys(group).forEach((key) => {
      const field = group.fields.find((f) => f.key === key);
      const value = this.drafts[key];
      // Back to the original text = drop the edit, so future updates to it come through.
      values[key] = field.default !== null && value === field.default ? null : value;
    });

    this.saveValues(values);
  }

  resetGroup(group) {
    if (!confirm(extractText(t('reset_all_confirm')))) return;

    const values = {};
    group.fields.filter((f) => f.edited).forEach((f) => (values[f.key] = null));
    this.saveValues(values);
  }

  saveValues(values) {
    if (!Object.keys(values).length) return;

    this.saving = true;
    this.errors = {};
    this.notice = null;

    app
      .request({ method: 'POST', url: api(), body: { locale: this.locale, values }, errorHandler: () => {} })
      .then(
        (res) => {
          this.saving = false;
          this.setData(res);
          this.drafts = {};
          this.notice = t('saved');
          m.redraw();
        },
        (error) => {
          this.saving = false;
          const res = error.response || {};
          if (res.groups) {
            this.setData(res);
            // Keep the rejected texts in the boxes so they can be fixed.
            this.drafts = {};
            Object.keys(res.errors || {}).forEach((key) => (this.drafts[key] = values[key]));
          }
          this.errors = res.errors || { _: extractText(t('save_failed')) };
          m.redraw();
        }
      );
  }

  previewBody(group) {
    const body = { locale: this.locale, kind: group ? group.kind : 'notification' };

    if (group) {
      group.fields.forEach((f) => {
        if (['subject', 'html', 'plain', 'body'].includes(f.role) && body[f.role] === undefined) {
          body[f.role] = this.draftValue(f);
        }
      });
    }

    if (this.tab === 'layout') body.layout = this.layout;

    return body;
  }

  runPreview(group) {
    this.previewing = true;

    app.request({ method: 'POST', url: api('/preview'), body: this.previewBody(group) }).then(
      (res) => {
        this.previewing = false;
        this.preview = res;
        if (this.previewTab === 'html' && !res.html) this.previewTab = 'text';
        m.redraw();
      },
      () => {
        this.previewing = false;
        m.redraw();
      }
    );
  }

  sendTest(group) {
    this.sending = true;

    app.request({ method: 'POST', url: api('/test'), body: this.previewBody(group) }).then(
      (res) => {
        this.sending = false;
        this.preview = res;
        app.alerts.show({ type: 'success' }, t('test_sent', { email: res.sentTo }));
        m.redraw();
      },
      () => {
        this.sending = false;
        m.redraw();
      }
    );
  }

  saveLayout() {
    const settings = {};

    Object.keys(LAYOUT_DEFAULTS).forEach((name) => {
      const value = this.layout[name];
      settings[SETTING + name] = typeof value === 'boolean' ? (value ? '1' : '0') : value;
    });

    this.saving = true;

    saveSettings(settings).then(
      () => {
        this.saving = false;
        this.layoutSaved = { ...this.layout };
        app.alerts.show({ type: 'success' }, t('saved'));
        m.redraw();
      },
      () => {
        this.saving = false;
        m.redraw();
      }
    );
  }

  insertPlaceholder(field, name) {
    const textarea = document.getElementById('EmailEditor-field-' + field.key.replace(/[^A-Za-z0-9_-]/g, '_'));
    const value = this.draftValue(field);
    const token = '{' + name + '}';

    let start = value.length;
    let end = value.length;
    if (textarea && typeof textarea.selectionStart === 'number') {
      start = textarea.selectionStart;
      end = textarea.selectionEnd;
    }

    this.drafts[field.key] = value.slice(0, start) + token + value.slice(end);

    if (textarea) {
      setTimeout(() => {
        textarea.focus();
        textarea.selectionStart = textarea.selectionEnd = start + token.length;
      });
    }
  }

  insertFooterTag(name) {
    const textarea = document.getElementById('EmailEditor-footerText');
    const value = this.layout.footer_text || '';
    const token = '{' + name + '}';
    const start = textarea ? textarea.selectionStart : value.length;
    const end = textarea ? textarea.selectionEnd : value.length;

    this.layout.footer_text = value.slice(0, start) + token + value.slice(end);

    if (textarea) {
      setTimeout(() => {
        textarea.focus();
        textarea.selectionStart = textarea.selectionEnd = start + token.length;
      });
    }
  }

  // ---------------------------------------------------------------- views

  content() {
    return (
      <div className="ExtensionPage-settings EmailEditor">
        <div className="container">
          <p className="helpText">{t('intro')}</p>

          <div className="EmailEditor-tabs">
            {this.tabButton('emails')}
            {this.tabButton('layout')}
            {this.data && this.data.locales.length > 1 && this.tab === 'emails' ? (
              <div className="EmailEditor-locale">
                <label>{t('language_label')}</label>
                <Select
                  value={this.locale}
                  options={Object.fromEntries(this.data.locales.map((l) => [l.code, l.name + ' (' + l.code + ')']))}
                  onchange={(value) => this.changeLocale(value)}
                />
              </div>
            ) : null}
          </div>

          {this.tab === 'emails' ? this.emailsTab() : this.layoutTab()}
        </div>
      </div>
    );
  }

  tabButton(key) {
    return (
      <Button
        className={classList('Button', { 'Button--primary': this.tab === key })}
        onclick={() => {
          if (this.tab === key) return;
          this.tab = key;
          this.preview = null;
        }}
      >
        {t('tab_' + key)}
      </Button>
    );
  }

  emailsTab() {
    if (!this.data) return <LoadingIndicator />;

    return (
      <div className="EmailEditor-panes">
        <div className="EmailEditor-listWrap">{this.list()}</div>
        <div className="EmailEditor-editor">{this.editor()}</div>
      </div>
    );
  }

  list() {
    const q = this.search.trim().toLowerCase();
    const byNamespace = {};

    this.data.groups.forEach((group) => {
      if (q) {
        const haystack = [this.extensionTitle(group.namespace), this.groupLabel(group)]
          .concat(group.fields.map((f) => f.key + ' ' + f.value))
          .join(' ')
          .toLowerCase();
        if (!haystack.includes(q)) return;
      }
      (byNamespace[group.namespace] = byNamespace[group.namespace] || []).push(group);
    });

    // Core first, then extensions by title.
    const namespaces = Object.keys(byNamespace).sort((a, b) => {
      if (a === 'core') return -1;
      if (b === 'core') return 1;
      return this.extensionTitle(a).localeCompare(this.extensionTitle(b));
    });

    return (
      <div>
        <input
          className="FormControl EmailEditor-search"
          type="search"
          placeholder={extractText(t('search_placeholder'))}
          value={this.search}
          oninput={(e) => (this.search = e.target.value)}
        />
        {namespaces.length ? (
          <div className="EmailEditor-list">
            {namespaces.map((ns) => (
              <div className="EmailEditor-listGroup" key={ns}>
                <div className="EmailEditor-listHeading">{this.extensionTitle(ns)}</div>
                <ul>
                  {byNamespace[ns]
                    .slice()
                    .sort((a, b) => (a.label === null ? -1 : b.label === null ? 1 : a.label.localeCompare(b.label)))
                    .map((group) => (
                      <li
                        key={group.id}
                        className={classList('EmailEditor-item', { active: this.selectedId === group.id })}
                        onclick={() => this.select(group.id)}
                      >
                        <span className="EmailEditor-itemLabel">{this.groupLabel(group)}</span>
                        {group.edited ? <span className="EmailEditor-badge">{t('edited_badge')}</span> : null}
                      </li>
                    ))}
                </ul>
              </div>
            ))}
          </div>
        ) : (
          <div className="EmailEditor-empty">{t('no_matches')}</div>
        )}
      </div>
    );
  }

  editor() {
    const group = this.groups[this.selectedId];
    if (!group) return <div className="EmailEditor-empty">{t('pick_one')}</div>;

    const dirty = this.dirtyKeys(group).length > 0;

    return (
      <div className="EmailEditor-email">
        <div className="EmailEditor-head">
          <div>
            <div className="EmailEditor-headExt">{this.extensionTitle(group.namespace)}</div>
            <div className="EmailEditor-headTitle">{this.groupLabel(group)}</div>
          </div>
        </div>

        {this.errors._ ? <div className="EmailEditor-error">{this.errors._}</div> : null}

        {group.fields.map((field) => this.fieldEditor(field, group))}

        <div className="EmailEditor-actions">
          <Button className="Button Button--primary" icon="fas fa-save" loading={this.saving} disabled={!dirty} onclick={() => this.save(group)}>
            {t('save_button')}
          </Button>
          <Button className="Button" icon="fas fa-undo" disabled={!dirty} onclick={() => (this.drafts = {})}>
            {t('undo_button')}
          </Button>
          <Button className="Button" icon="fas fa-eye" loading={this.previewing} onclick={() => this.runPreview(group)}>
            {t('preview_button')}
          </Button>
          <Button className="Button" icon="fas fa-paper-plane" loading={this.sending} onclick={() => this.sendTest(group)}>
            {t('send_test_button')}
          </Button>
          {group.edited ? (
            <Button className="Button Button--danger" icon="fas fa-history" onclick={() => this.resetGroup(group)}>
              {t('reset_all_button')}
            </Button>
          ) : null}
          {this.notice && !dirty ? <span className="EmailEditor-notice">{this.notice}</span> : null}
        </div>

        <p className="helpText EmailEditor-previewHelp">{t(group.kind === 'information' ? 'preview_help_information' : 'preview_help_notification')}</p>

        {this.previewPane()}
      </div>
    );
  }

  fieldEditor(field, group) {
    const value = this.draftValue(field);
    // Tags every email text can use, minus ones this text already has, and
    // the notification-only ones in account emails.
    const tags = (this.data.tags || [])
      .filter((tag) => !field.placeholders.includes(tag.name))
      .filter((tag) => !(tag.notificationOnly && group.kind === 'information'))
      .map((tag) => tag.name);
    const id = 'EmailEditor-field-' + field.key.replace(/[^A-Za-z0-9_-]/g, '_');
    const differs = field.default !== null && value !== field.default;
    const rows = Math.min(14, Math.max(field.role === 'subject' ? 1 : 3, value.split('\n').length + 1));

    return (
      <div className={classList('EmailEditor-field', { 'EmailEditor-field--error': this.errors[field.key] })} key={field.key}>
        <div className="EmailEditor-fieldHead">
          <label for={id}>{this.fieldLabel(field)}</label>
          {field.edited ? <span className="EmailEditor-badge">{t('edited_badge')}</span> : null}
          <code className="EmailEditor-key">{field.key}</code>
          {this.tagMenu(field.placeholders, tags, (name) => this.insertPlaceholder(field, name))}
        </div>

        <textarea
          id={id}
          className="FormControl EmailEditor-textarea"
          rows={rows}
          value={value}
          oninput={(e) => (this.drafts[field.key] = e.target.value)}
        />

        {this.errors[field.key] ? <div className="EmailEditor-error">{this.errors[field.key]}</div> : null}

        <div className="EmailEditor-fieldTools">
          <span />

          {differs ? (
            <span className="EmailEditor-originalTools">
              <button type="button" className="EmailEditor-link" onclick={() => (this.showOriginal[field.key] = !this.showOriginal[field.key])}>
                {this.showOriginal[field.key] ? t('hide_original') : t('show_original')}
              </button>
              <button type="button" className="EmailEditor-link" onclick={() => (this.drafts[field.key] = field.default)}>
                {t('use_original')}
              </button>
            </span>
          ) : null}
        </div>

        {differs && this.showOriginal[field.key] ? <pre className="EmailEditor-original">{field.default || t('empty_text')}</pre> : null}
        {field.default === null && field.edited ? <div className="helpText">{t('original_unknown')}</div> : null}
      </div>
    );
  }

  /**
   * The "Insert tag" button: a list of this text's own placeholders and the
   * tags every email can use, each with what it is. Clicking one inserts it.
   */
  tagMenu(own, shared, insert) {
    if (!own.length && !shared.length) return null;

    const item = (name, isOwn) => (
      <Button className="EmailEditor-tagItem" onclick={() => insert(name)}>
        <code>{'{' + name + '}'}</code>
        <span className="EmailEditor-tagHelp">{this.tagHelp(name, isOwn)}</span>
      </Button>
    );

    return (
      <Dropdown
        className="EmailEditor-tagMenu"
        buttonClassName="Button Button--text"
        menuClassName="Dropdown-menu--right"
        icon="fas fa-tag"
        caretIcon="fas fa-caret-down"
        label={t('insert_tag')}
        lazyDraw
      >
        {own.length ? <MenuHeading label={t('tags_this_text')} /> : null}
        {own.map((name) => item(name, true))}
        {shared.length ? <MenuHeading label={t('tags_all_emails')} /> : null}
        {shared.map((name) => item(name, false))}
      </Dropdown>
    );
  }

  /** What a tag is, or '' if it's one we don't know. */
  tagHelp(name, isOwn) {
    // A missing translation comes back as its own key.
    const has = (key) => extractText(t(key)) !== PREFIX + key;

    // A text's own placeholder can mean something else than the shared tag of
    // the same name ({username} in a subject is often the poster), so own
    // placeholders use their own wording first.
    for (const key of isOwn ? ['placeholder_help.' + name, 'tag_help.' + name] : ['tag_help.' + name]) {
      if (has(key)) return t(key);
    }

    return '';
  }

  previewPane() {
    if (!this.preview) return null;

    const p = this.preview;
    const tab = (key) => (
      <Button className={classList('Button', { 'Button--primary': this.previewTab === key })} onclick={() => (this.previewTab = key)}>
        {t('preview_tab_' + key)}
      </Button>
    );

    return (
      <div className="EmailEditor-preview">
        <div className="EmailEditor-previewSubject">
          <span className="EmailEditor-toolLabel">{t('field_subject')}</span> {p.subject}
        </div>
        <div className="EmailEditor-previewTabs">
          {tab('html')}
          {tab('text')}
        </div>
        {this.previewTab === 'html' ? (
          // Sandboxed: no scripts run; links open in a new tab.
          <iframe className="EmailEditor-frame" sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc={'<base target="_blank">' + p.html} />
        ) : (
          <pre className="EmailEditor-pre">{p.text}</pre>
        )}
      </div>
    );
  }

  layoutTab() {
    const L = this.layout;
    const set = (name) => (value) => (this.layout[name] = value);

    return (
      <div className="EmailEditor-layout">
        <div className="Form-group">
          <Switch state={L.custom_layout} onchange={set('custom_layout')}>
            {t('layout.custom_layout')}
          </Switch>
          <div className="helpText">{t('layout.custom_layout_help')}</div>
        </div>

        <fieldset className={classList('EmailEditor-layoutOptions', { disabled: !L.custom_layout })} disabled={!L.custom_layout}>
          <div className="Form-group">
            <Switch state={L.show_title} onchange={set('show_title')}>
              {t('layout.show_title')}
            </Switch>
            <Switch state={L.show_greeting} onchange={set('show_greeting')}>
              {t('layout.show_greeting')}
            </Switch>
            <Switch state={L.show_signoff} onchange={set('show_signoff')}>
              {t('layout.show_signoff')}
            </Switch>
            <div className="helpText">{t('layout.wording_help')}</div>
          </div>

          <div className="Form-group">
            <label>{t('layout.logo_align')}</label>
            <Select
              value={L.logo_align}
              options={{ left: extractText(t('layout.align_left')), center: extractText(t('layout.align_center')) }}
              onchange={set('logo_align')}
            />
          </div>

          <div className="Form-group">
            <label>{t('layout.colors')}</label>
            <div className="EmailEditor-colors">{COLORS.map((name) => this.colorInput(name))}</div>
          </div>

          <div className="Form-group">
            <div className="EmailEditor-fieldHead">
              <label for="EmailEditor-footerText">{t('layout.footer_text')}</label>
              {this.data && this.data.tags ? this.tagMenu([], this.data.tags.map((tag) => tag.name), (name) => this.insertFooterTag(name)) : null}
            </div>
            <textarea
              id="EmailEditor-footerText"
              className="FormControl"
              rows={3}
              value={L.footer_text}
              oninput={(e) => (L.footer_text = e.target.value)}
            />
            <div className="helpText">{t('layout.footer_text_help')}</div>
          </div>
        </fieldset>

        <div className="EmailEditor-actions">
          <Button className="Button Button--primary" icon="fas fa-save" loading={this.saving} disabled={!this.layoutDirty()} onclick={() => this.saveLayout()}>
            {t('save_button')}
          </Button>
          <Button className="Button" icon="fas fa-undo" disabled={!this.layoutDirty()} onclick={() => (this.layout = { ...this.layoutSaved })}>
            {t('undo_button')}
          </Button>
          <Button className="Button" icon="fas fa-eye" loading={this.previewing} onclick={() => this.runPreview(null)}>
            {t('preview_button')}
          </Button>
          <Button className="Button" icon="fas fa-paper-plane" loading={this.sending} onclick={() => this.sendTest(null)}>
            {t('send_test_button')}
          </Button>
        </div>
        <p className="helpText EmailEditor-previewHelp">{t('layout.preview_help')}</p>

        {this.previewPane()}
      </div>
    );
  }

  colorInput(name) {
    const value = this.layout[name];
    const optional = name === 'color_accent';

    return (
      <div className="EmailEditor-color" key={name}>
        <span className="EmailEditor-colorLabel">{t('layout.' + name)}</span>
        <span className="EmailEditor-colorInputs">
          <input type="color" value={value || '#000000'} oninput={(e) => (this.layout[name] = e.target.value)} />
          <input
            className="FormControl"
            type="text"
            value={value}
            placeholder={optional ? extractText(t('layout.color_none')) : ''}
            oninput={(e) => (this.layout[name] = e.target.value.trim())}
          />
          {optional && value ? (
            <button type="button" className="EmailEditor-link" onclick={() => (this.layout[name] = '')}>
              {t('layout.color_clear')}
            </button>
          ) : null}
        </span>
      </div>
    );
  }
}
