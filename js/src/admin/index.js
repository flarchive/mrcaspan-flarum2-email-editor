import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import EmailEditorPage from './components/EmailEditorPage';

export const extend = [new Extend.Admin().page(EmailEditorPage)];

app.initializers.add('mrcaspan-flarum2-email-editor', () => {});
