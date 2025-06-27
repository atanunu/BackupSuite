<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Backup\Bootstrap;
use Backup\Router;

Bootstrap::init(__DIR__ . '/../config/config.php');
Router::dispatch();
