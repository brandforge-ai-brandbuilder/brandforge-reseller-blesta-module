<?php
/**
 * Shared base for every BrandForge plugin controller — sets the default
 * view path and loads the handler's language pack, same as every
 * admin_* controller here would otherwise repeat individually.
 *
 * The structure/chrome handling below is copied in sequence — verified
 * against Blesta 6.0.2 core source, not guessed — from the stock `order`
 * plugin's own OrderController::preAction(). Confirmed empirically on a
 * live install, in two stages:
 *
 * 1. Dispatcher calls Controller::setDefaultViewPath($pluginPath) before
 *    preAction() ever runs, pointing BOTH $this->view and $this->structure
 *    at our plugin directory. Left alone, Blesta looks for the page chrome
 *    *inside our plugin* instead of its own app directory, and a bare
 *    plugin-local `structure.pdt` only gets the OTHER, genuinely minimal
 *    "default" theme structure (APPDIR/views/default/structure.pdt) — no
 *    sidebar/top-bar at all. Restoring `$this->structure`'s path to APPDIR
 *    fixes that part: the real admin/paradigm structure.pdt renders.
 * 2. That's not sufficient on its own, though: AppController::preAction()
 *    (core, encrypted) is what registers this request's CSS/JS asset list,
 *    and it reads $this->structure's view path at the moment IT runs — so
 *    if the APPDIR restore happens only *after* calling parent::preAction()
 *    (as an earlier version of this file did), the sidebar/structure markup
 *    comes out right but the <link>/<script> tags are already baked in
 *    pointing at a nonexistent plugins/brandforge/views/admin/paradigm/...
 *    path (404s in the console, page renders fully unstyled). The fix has
 *    to run BEFORE parent::preAction(), exactly where OrderController does
 *    it, so AppController's own asset registration sees the correct path
 *    from the start.
 */
class BrandforgeController extends AppController
{
    public function preAction()
    {
        $this->structure->setDefaultView(APPDIR);

        parent::preAction();

        $this->view->setDefaultView('plugins' . DS . 'brandforge' . DS);
        $this->view->view = 'default';

        Language::loadLang('brandforge_plugin', null, PLUGINDIR . 'brandforge' . DS . 'language' . DS);
    }
}
