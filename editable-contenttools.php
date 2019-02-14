<?php
namespace Grav\Plugin;

use Grav\Common\Page\Page;
use Grav\Common\Plugin;
use Grav\Common\Utils;

/**
 * Class EditableContentToolsPlugin
 * @package Grav\Plugin
 */
class EditableContentToolsPlugin extends Plugin
{

    protected $my_name = 'editable-contenttools';
    protected $my_full_name = 'Editable ContentTools';
    protected $token = 'editable-contenttools-api';

    /**
     * Add Editor code and styles
     */
    public function addAssets()
    {
        // Get assets objects
        $assets = $this->grav['assets'];

        // Add styles
        $assets->addCss('plugin://' . $this->my_name . '/vendor/content-tools.min.css', 1);
        $assets->addCss('plugin://' . $this->my_name . '/css/editor.css', 1);

        // Add code
        $assets->addJs('plugin://' . $this->my_name . '/vendor/turndown.js', 1);
        $assets->addJs('plugin://' . $this->my_name . '/vendor/content-tools.min.js', 1);
        $assets->AddJs('plugin://' . $this->my_name . '/vendor/turndown-plugin-gfm.js', 1);

        // Add reference to dynamically created assets
        $route = $this->grav['page']->route();
        if ($route == '/') {
            $route = '';
        }
        $assets->addJs($this->my_name . '-api' . $route . '/editor.js', ['group' => 'bottom']);
    }

    /**
     * This will execute $cmd in the background (no cmd window)
     * without PHP waiting for it to finish, on both Windows and Unix.
     * http://php.net/manual/en/function.exec.php#86329
     *
     * Not tested on Windows by plugin dev
     *
     */
    public function execInBackground($cmd)
    {
        if (strtolower(substr(php_uname('s'), 0, 3)) == "win") {
            pclose(popen("start /B " . $cmd, "r"));
        } else {
            exec($cmd . " > /dev/null &");
        }
    }

    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    /**
     * When a user is authorized preprocess editable region shortcodes
     * and add Editor to the page
     */
    public function onPageInitialized()
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            $this->active = false;
            return;
        }

        $page = $this->grav['page'];
        $content = $page->rawMarkdown();

        if ($this->userAuthorized()) {

            // Check shortcode names
            // Insert when missing: [editable] | [editable name=""]
            // Renumber existing "reserved" values, e.g.: [editable name="region-3"]
            $re = '/((\[editable)(( +name="(region-[0-9]*)*") *\]|\]))/is';

            preg_match_all($re, $content, $matches, PREG_SET_ORDER, 0);

            $i = 0;
            foreach ($matches as $match) {

                // Insert or replace name parameter
                $pos = strpos($content, $match[0]);
                if ($pos !== false) {
                    $content = substr_replace($content, '[editable name="region-' . $i . '"]', $pos, strlen($match[0]));
                }
                $i++;
            }

            // If names were changed save the page
            if ($i > 0) {
                // Do the actual save action
                $page->rawMarkdown($content);
                $page->save();
                $this->grav['pages']->dispatch($page->route());
            }

            // Process shortcodes by parsing to HTML avoiding Twig and Parsedown Extra
            $re = '/\[editable name="(.*?)"\](.*?)\[\/editable\]/is';
            preg_match_all($re, $content, $matches, PREG_SET_ORDER, 0);

            $parsedown = new \Parsedown();
            foreach ($matches as $match) {
                $find = $match[0];
                $html = $parsedown->text($match[2]);
                $replace = '<div data-editable data-name="' . $match[1] . '">' . $html . '</div>';
                $content = str_replace($find, $replace, $content);
            }

            $page->rawMarkdown($content);

            $this->addAssets();

            // Update the current Page object content
            // The call to Page::content() recaches the page. If not done a browser
            // page refresh is required to properly initialize the ContentTools editor
            $this->grav['page']->content($page->content());
            
        }
        else {
            // Remove all shortcodes
            $re = '/\[editable name=".*?"\](.*?)\[\/editable\]/is';
            preg_match_all($re, $content, $matches, PREG_SET_ORDER, 0);

            $parsedown = new \Parsedown();
            foreach ($matches as $match) {
                $find = $match[0];
                $replace = $parsedown->text($match[1]);
                $content = str_replace($find, $replace, $content);
            }

            $page->rawMarkdown($content);
        }
    }

    /**
     * Pass valid actions (via AJAX requests) on to the editor resource to handle
     *
     * @return the output of the editor resource
     */
    public function onPagesInitialized()
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            $this->active = false;
            return;
        }

        $paths = $this->grav['uri']->paths();

        // Check whether action is required here
        if (array_shift($paths) == $this->token) {
            $target = array_pop($paths);
            $route = implode('/', $paths);

            switch ($target) {

                case 'editor.js': // Return editor instantiation as Javascript
                    $nonce = Utils::getNonce($this->my_name . '-nonce');

                    // Create absolute URL including token and action
                    $save_url = $this->grav['uri']->rootUrl(true) . '/' . $this->token . '/' . $route . '/save';
                    // Render the template
                    $output = $this->grav['twig']->processTemplate('editor.js.twig', [
                        'save_url' => $save_url,
                        'nonce' => $nonce,
                    ]);

                    $this->setHeaders('text/javascript');
                    echo $output;
                    exit;

                case 'save':
                    if ($_POST) {
                        $this->saveRegions('/' . $route);
                    }

                default:
                    return;
            }
        }
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            return;
        }

        // Enable the events we are interested in
        $this->enable([
            'onPageInitialized' => ['onPageInitialized', 0],
            'onPagesInitialized' => ['onPagesInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0]
        ]);
    }

    /**
     * Add current directory to Twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        // Add local templates folder to the Twig templates search path
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Save each region content to it's corresponding shortcode
     */
    public function saveRegions($route)
    {
        $result = false;
        $post = $_POST;
        $nonce = $post['ct-nonce'];

        if (Utils::verifyNonce($nonce, $this->my_name . '-nonce')) {
            $page = $this->grav['pages']->find($route);
            $content = $page->rawMarkdown();

            foreach ($post as $key => $value) {
                // Replace each shortcode content
                if (preg_match('/\[editable name="' . $key . '"\](.*?)\[\/editable\]/is', $content, $matches) == 1) {
                    $find = $matches[0];
                    $replace = '[editable name="' . $key . '"]' . $value . '[/editable]';
                    $content = str_replace($find, $replace, $content);
                }
            }

            // Do the actual save action
            $page->rawMarkdown($content);
            $page->save();

            // Trigger Git Sync
            $config = $this->grav['config'];

            if ($config->get('plugins.git-sync.enabled') &&
                $config->get('plugins.editable-contenttools.git-sync')) {
                if ($config->get('plugins.editable-contenttools.git-sync-mode') == 'background') {

                    $command = GRAV_ROOT . '/bin/plugin git-sync sync';
                    $this->execInBackground($command);

                } else {

                    $this->grav->fireEvent('gitsync');

                }

            }

            exit;
        }

        // Saving failed
        // Create a custom error page
        // BTW the HTTP status code is set via the page frontmatter
        $page = new Page;
        $page->init(new \SplFileInfo(__DIR__ . '/pages/save-error.md'));

        // Let Grav return the error page
        unset($this->grav['page']);
        $this->grav['page'] = $page;

    }

    /**
     * Set return header
     *
     * @return header
     */
    public function setHeaders($type = 'application/json')
    {
        header('Content-type: ' . $type);

        // Calculate Expires Headers if set to > 0
        $expires = $this->grav['config']->get('system.pages.expires');
        if ($expires > 0) {
            $expires_date = gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT';
            header('Cache-Control: max-age=' . $expires);
            header('Expires: ' . $expires_date);
        }
    }

    /**
     * Check that the user is permitted to edit
     *
     * @return boolean
     */
    public function userAuthorized()
    {
        $result = false;
        $user = $this->grav['user'];

        if ($user->authorized) {
            $result = $user->authorize('site.editable') || $user->authorize('admin.super') || $user->authorize('admin.pages');
        }
        return $result;
    }

}
