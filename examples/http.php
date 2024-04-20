<?php

namespace http;

use Castor\Attribute\AsTask;

use function Castor\http_download;
use function Castor\http_request;
use function Castor\io;

#[AsTask(description: 'Make HTTP request')]
function request(): void
{
    $url = $_SERVER['ENDPOINT'] ?? 'https://example.com';

    $response = http_request('GET', $url);

    io()->writeln($response->getContent());
}

#[AsTask(description: 'Download a file through HTTP')]
function download(): void
{
    http_download('http://eu-central-1.linodeobjects.com/speedtest/10MB-speedtest');
}
