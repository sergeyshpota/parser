<?php
require __DIR__ . '/vendor/autoload.php';
require 'SiteCrawler.php';

echo "\nProcess crawling has been started\n\n";

(new SiteCrawler('domains.txt'))->startCrawl();

echo "\nProcess crawling has been finished\n";
