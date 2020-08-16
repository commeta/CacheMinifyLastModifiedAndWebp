<?php
/**
 * MODx Revolution plugin which handle request If-Modified-Since
 *
 * @package lastmodified
 *
 * @var modX    $modx       MODX instance
 * @var array   $prevent    Prevent handling list
 * @var integer $dtm        Value of last update time of document
 * @var integer $ltm        Value of HTTP_IF_MODIFIED_SINCE from request
 * @var string  $rule       Cache-control directive (public, private)
 * @var integer $maxage     Cache max age in seconds
 * @var integer $expire     Cache expire in seconds
 */

 
if ($modx->event->name == 'OnWebPagePrerender') {
    if ($modx->getOption('lastmodified.prevent_authorized') && ($modx->user->get('username') !== $modx->getOption('default_username'))) {
        return '';
    }

    if (!empty($modx->getOption('lastmodified.prevent_session'))) {
        $prevent = array_map(function ($s) {return strtolower(trim($s));}, explode(',', $modx->getOption('lastmodified.prevent_session')));
        if (empty($prevent)) {
            $modx->log(xPDO::LOG_LEVEL_ERROR, 'LastModified: incorrect prevent session list. Check configuration.');
            return '';
        }

        $sessionkeys = array_map(function ($s) {return strtolower(trim($s));}, array_keys($_SESSION));

        if (array_intersect($prevent, $sessionkeys)) {
            return '';
        }
    }

    $dtm = $modx->resource->get('editedon') ? strtotime($modx->resource->get('editedon')) : strtotime($modx->resource->get('createdon'));
    if (empty($dtm)) {
        return '';
    }

    $rule = trim($modx->getOption('lastmodified.response'));

    if (!in_array($rule, ['private', 'public'])) { // 'no-cache'
        $modx->log(xPDO::LOG_LEVEL_ERROR, 'LastModified: wrong response directive value. Check configuration.');
        return '';
    }

    $maxage = ((int)$modx->getOption('lastmodified.maxage') > 0) ? (int)$modx->getOption('lastmodified.maxage') : 3600;
    $expire = ((int)$modx->getOption('lastmodified.expires') > 0) ? (int)$modx->getOption('lastmodified.expires') : 3600;

    if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        $ltm = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
        if ($dtm <= $ltm) {
            $protocol = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
            header($protocol . ' 304 Not Modified');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $dtm) . ' GMT');
            header('Cache-control: ' . $rule . ', max-age=' . $maxage);
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $expire));
            exit();
        }
    }
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $dtm) . ' GMT');
    header('Cache-control: ' . $rule . ', max-age=' . $maxage);
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $expire));
    

    // Minify & Cache
    $options = array(xPDO::OPT_CACHE_KEY=>'minify_page');
    
    if( strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' ) !== false ) { // webp is supported!
        $cache_key= md5( MODX_SITE_URL.parse_url($_SERVER['REQUEST_URI'])['path'].'webp' );
    } else {
        $cache_key= md5( MODX_SITE_URL.parse_url($_SERVER['REQUEST_URI'])['path'] );
    }
    
    $cached_page= $modx->cacheManager->get($cache_key, $options);
    $output= &$modx->resource->_output;

    if( empty($cached_page) ){
        minify_html($output);

        $modx->cacheManager->set($cache_key, $output, 0, $options);
    } else {
        die($cached_page);
    }
    
    return '';
}

/**
 * Update parent editedon field
 *
 * @var modX $modx MODX instance
 * @var int $id The id of document for saving available for OnDooFormSave event
 * @var modResource $parent Parent resource object
 */
if ($modx->event->name == 'OnDocFormSave') {
    if ($modx->getOption('lastmodified.update_start')) {
        $mainId = $modx->getOption('site_start');

        if ($mainId > 0 && $mainId !== $id) {
            $main = $modx->getObject('modResource', $mainId);

            if (!$main instanceof modResource) {
                $modx->log(xPDO::LOG_LEVEL_ERROR, 'LastModified: get wrong modResource instance for main page with id ' . $mainId . ' for document ' . $id. '.');
                return '';
            }

            $main->set('editedon', time());
            $main->save();

            unset($main);
        }
        unset($mainId);
    }

    if ($modx->getOption('lastmodified.update_parent')) {
        $level = ((int)$modx->getOption('lastmodified.update_level') > 0) ? (int)$modx->getOption('lastmodified.update_level') : 1;

        $parentIds = $modx->getParentIds($id, $level, ['context' => $resource->context_key]);

        if (empty($parentIds)) {
            $modx->log(xPDO::LOG_LEVEL_ERROR, 'LastModified: get empty ParentIds array. Possible context violation.');
            return '';
        }

        foreach ($parentIds as $parentId) {
            if ($parentId === 0) {
                continue;
            }

            $parent = $modx->getObject('modResource', $parentId);

            if (!$parent instanceof modResource) {
                $modx->log(xPDO::LOG_LEVEL_ERROR, 'LastModified: get wrong modResource instance for parent with id ' . $parentId . ' for document ' . $id. '.');
                return '';
            }

            $parent->set('editedon', time());
            $parent->save();

            unset($parent);
        }

        return '';
    }
}


function minify_html(&$output){
    //remove redundant (white-space) characters
    $replace = array(
        //remove tabs before and after HTML tags
        '/\>[^\S ]+/s'   => '>',
        '/[^\S ]+\</s'   => '<',
        //shorten multiple whitespace sequences; keep new-line characters because they matter in JS!!!
        '/([\t ])+/s'  => ' ',
        //remove leading and trailing spaces
        '/^([\t ])+/m' => '',
        '/([\t ])+$/m' => '',
        // remove JS line comments (simple only); do NOT remove lines containing URL (e.g. 'src="http://server.com/"')!!!
        '~//[a-zA-Z0-9 ]+$~m' => '',
        //remove empty lines (sequence of line-end and white-space characters)
        '/[\r\n]+([\t ]?[\r\n]+)+/s'  => "\n",
        //remove empty lines (between HTML tags); cannot remove just any line-end characters because in inline JS they can matter!
        '/\>[\r\n\t ]+\</s'    => '><',
        //remove "empty" lines containing only JS's block end character; join with next line (e.g. "}\n}\n</script>" --> "}}</script>"
        '/}[\r\n\t ]+/s'  => '}',
        '/}[\r\n\t ]+,[\r\n\t ]+/s'  => '},',
        //remove new-line after JS's function or condition start; join with next line
        '/\)[\r\n\t ]?{[\r\n\t ]+/s'  => '){',
        '/,[\r\n\t ]?{[\r\n\t ]+/s'  => ',{',
        //remove new-line after JS's line end (only most obvious and safe cases)
        '/\),[\r\n\t ]+/s'  => '),',
        //remove quotes from HTML attributes that does not contain spaces; keep quotes around URLs!
        '~([\r\n\t ])?([a-zA-Z0-9]+)="([a-zA-Z0-9_/\\-]+)"([\r\n\t ])?~s' => '$1$2=$3$4', //$1 and $4 insert first white-space character found before/after attribute
    );
    $output = preg_replace(array_keys($replace), array_values($replace), $output);
} 
