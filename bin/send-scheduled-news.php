<?php

/**
 * Sends scheduled News announcements that are due. Every News email goes from
 * hr@menakantransport.com (Menakan Transport HR Department) through the SMTP server in .env;
 * the sender is checked with the server before each send.
 *
 * Windows Task Scheduler (every 5 minutes):
 *   C:\xampp\php\php.exe C:\path\to\menakan-hrms\bin\send-scheduled-news.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/config/bootstrap.php';

use App\Services\NewsService;

$n = NewsService::sendDue();
fwrite(STDOUT, sprintf("%d scheduled announcement(s) processed.\n", $n));