import app from 'flarum/admin/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import extractText from 'flarum/common/utils/extractText';

const t = (key, params) => app.translator.trans('mrcaspan-flarum2-email-editor.admin.' + key, params);

/**
 * Pick a tag to insert, from one list: this text's own placeholders, then the
 * tags every email can use, each with what it is. A shared tag that means the
 * same as one of the text's own ({forumTitle} next to {forum}) is left out.
 * Clicking one inserts it and closes.
 *
 * attrs: own (string[]), shared (string[]), help(name, isOwn), onselect(name)
 */
export default class TagModal extends Modal {
  className() {
    return 'EmailEditor-tagModal Modal--small';
  }

  title() {
    return t('insert_tag');
  }

  content() {
    return <div className="Modal-body">{this.tags().map(({ name, help }) => this.item(name, help))}</div>;
  }

  tags() {
    const { own = [], shared = [], help } = this.attrs;
    const list = own.map((name) => ({ name, help: help(name, true) }));
    const ownHelp = new Set(list.map((tag) => extractText(tag.help)).filter(Boolean));

    shared.forEach((name) => {
      const text = help(name, false);
      if (own.includes(name) || (extractText(text) && ownHelp.has(extractText(text)))) return;
      list.push({ name, help: text });
    });

    return list;
  }

  item(name, help) {
    return (
      <Button className="Button EmailEditor-tagItem" onclick={() => this.pick(name)}>
        <code>{'{' + name + '}'}</code>
        {help ? <span className="EmailEditor-tagHelp">{help}</span> : null}
      </Button>
    );
  }

  pick(name) {
    this.hide();
    this.attrs.onselect(name);
  }
}
