<?php

declare(strict_types=1);

namespace Tests\Fixtures\AnnotatedApp\Controllers;

use Geni\Laravel\Attributes\Endpoint;
use Geni\Laravel\Attributes\Group;
use Geni\Laravel\Attributes\PathParameter;
use Geni\Laravel\Attributes\QueryParameter;
use Geni\Laravel\Attributes\Response as ResponseAttribute;
use Illuminate\Routing\Controller;
use Tests\Fixtures\AnnotatedApp\Models\Article;
use Tests\Fixtures\AnnotatedApp\Requests\StoreArticleRequest;
use Tests\Fixtures\AnnotatedApp\Resources\ArticleResource;

#[Group(name: 'Articles', description: 'Article management endpoints')]
class ArticleController extends Controller
{
    /**
     * @response 200 \Tests\Fixtures\AnnotatedApp\Resources\ArticleResource
     *
     * @operationId listArticles
     *
     * @tags Articles, Publications
     */
    #[Endpoint(operationId: 'listArticles', title: 'List Articles')]
    #[QueryParameter(name: 'page', description: 'Page number', required: false, type: 'integer', infer: false, default: 1)]
    #[QueryParameter(name: 'search', description: 'Search term', required: false, type: 'string')]
    public function index(): ArticleResource
    {
        return new ArticleResource(new Article(['title' => 'Sample']));
    }

    /**
     * @response 201 \Tests\Fixtures\AnnotatedApp\Resources\ArticleResource
     */
    #[ResponseAttribute(status: 201, description: 'Article created')]
    public function store(StoreArticleRequest $request): ArticleResource
    {
        return new ArticleResource(new Article($request->validated()));
    }

    /**
     * @response 200 \Tests\Fixtures\AnnotatedApp\Resources\ArticleResource
     */
    #[PathParameter(name: 'article', description: 'Article identifier', required: true, type: 'integer', infer: false)]
    public function show(Article $article): ArticleResource
    {
        return new ArticleResource($article);
    }
}
