<?php

namespace App\Controllers;

use App\Libraries\Cors;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

abstract class BaseController extends Controller
{
    protected $helpers = [];

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        // Only the websites listed in CORS_ALLOWED_ORIGINS (see App\Libraries\Cors) get these headers.
        Cors::apply($this->response, $request->getHeaderLine('Origin'));
    }
}