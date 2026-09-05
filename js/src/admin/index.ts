import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';
import DashboardPage from 'flarum/admin/components/DashboardPage';
import UpdateBanner from './components/UpdateBanner';

declare const m: any;

app.initializers.add('ernestdefoe/millwright', () => {
  /*
   * The banner goes on the DASHBOARD, because that is the page an admin lands
   * on. A notice only visible on Millwright's own page would only ever be read
   * by somebody who already went looking, which is not who needs telling.
   *
   * 🚨 Done with extend() rather than an extender: there is no declarative
   * extender for adding to another page's content in Flarum 2. I looked for one
   * before assuming — `Extend.PageProvider` does not exist.
   */
  extend(DashboardPage.prototype, 'content', function (items: any[]) {
    items.unshift(m(UpdateBanner));
  });
});

export { default as extend } from './extend';
