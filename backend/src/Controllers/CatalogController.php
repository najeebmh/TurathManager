<?php

declare(strict_types=1);

namespace TurathManager\Controllers;

use TurathManager\Core\HttpException;
use TurathManager\Core\Request;
use TurathManager\Core\Response;
use TurathManager\Services\CategoryService;
use TurathManager\Services\ProductService;

final class CatalogController extends Controller
{
    public function categories(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(CategoryService::class)->list($r->query('archived') === '1')]);
    }

    public function storeCategory(Request $r): Response
    {
        return Response::created(['data' => $this->app->service(CategoryService::class)->saveCategory($r->body['id'] ?? null, $r->body, $this->user($r), $r)]);
    }

    public function updateCategory(Request $r): Response
    {
        $s = $this->app->service(CategoryService::class);
        $id = $this->id($r);
        $s->getCategory($id);
        return Response::json(['data' => $s->saveCategory($id, $r->body, $this->user($r), $r, isset($r->body['version']) ? (int) $r->body['version'] : null)]);
    }

    public function archiveCategory(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(CategoryService::class)->archiveCategory($this->id($r), (bool) ($r->body['archived'] ?? true), $this->user($r), $r)]);
    }

    public function storeType(Request $r): Response
    {
        return Response::created(['data' => $this->app->service(CategoryService::class)->saveType($r->body['id'] ?? null, $r->body, $this->user($r), $r)]);
    }

    public function updateType(Request $r): Response
    {
        $s = $this->app->service(CategoryService::class);
        $id = $this->id($r);
        $s->getType($id);
        return Response::json(['data' => $s->saveType($id, $r->body, $this->user($r), $r, isset($r->body['version']) ? (int) $r->body['version'] : null)]);
    }

    public function archiveType(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(CategoryService::class)->archiveType($this->id($r), (bool) ($r->body['archived'] ?? true), $this->user($r), $r)]);
    }

    public function products(Request $r): Response
    {
        [$limit, $offset] = $this->paging($r);
        return Response::json($this->app->service(ProductService::class)->list($r->query, $limit, $offset));
    }

    public function product(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(ProductService::class)->details($this->id($r))]);
    }

    public function storeProduct(Request $r): Response
    {
        return Response::created(['data' => $this->app->service(ProductService::class)->create($r->body, $this->user($r), $r)]);
    }

    public function updateProduct(Request $r): Response
    {
        $body = $r->body;
        $version = isset($body['version']) ? (int) $body['version'] : null;
        unset($body['version'], $body['id']);
        return Response::json(['data' => $this->app->service(ProductService::class)->update($this->id($r), $body, $this->user($r), $r, $version)]);
    }

    public function archiveProduct(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(ProductService::class)->archive($this->id($r), (bool) ($r->body['archived'] ?? true), $this->user($r), $r)]);
    }

    public function deleteProduct(Request $r): Response
    {
        $this->app->service(ProductService::class)->delete($this->id($r), $this->user($r), $r);
        return Response::noContent();
    }

    public function addMovement(Request $r): Response
    {
        return Response::created(['data' => $this->app->service(ProductService::class)->addMovement($this->id($r), $r->body, $this->user($r), $r)]);
    }

    public function movements(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(ProductService::class)->movements($this->id($r))]);
    }

    public function uploadImage(Request $r): Response
    {
        $file = $r->files['image'] ?? null;
        if (!is_array($file)) {
            throw HttpException::validation(['image' => 'يجب اختيار صورة']);
        }
        $imageId = $r->header('idempotency-key') ?? ($r->body['id'] ?? null);
        return Response::created(['data' => $this->app->service(ProductService::class)->addImage($this->id($r), $file, $this->user($r), $imageId, $r)]);
    }

    public function deleteImage(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(ProductService::class)->removeImage($this->id($r), $this->id($r, 'imageId'), $this->user($r), $r)]);
    }

    public function primaryImage(Request $r): Response
    {
        return Response::json(['data' => $this->app->service(ProductService::class)->setPrimaryImage($this->id($r), $this->id($r, 'imageId'), $this->user($r))]);
    }
}
