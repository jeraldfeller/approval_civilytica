<?php

$loader = new \Phalcon\Loader();
$loader->registerDirs(array_values($config->directories->toArray()));
$loader->registerNamespaces([
    'Aiden\Models' => $config->directories->modelsDir,
]);
$loader->register();
