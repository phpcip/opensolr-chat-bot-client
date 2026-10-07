<?php
require __DIR__ . '/../../vendor/autoload.php';
(new Opensolr\ChatBot\App([
    'data_dir'  => '/opt/opensolr-chat',   // outside the web root, writable by PHP; the SQLite file lives here
    'base_path' => '/opensolr-chat',       // the URL path this file answers on
]))->run();
