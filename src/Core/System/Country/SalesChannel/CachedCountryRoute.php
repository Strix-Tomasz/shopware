<?php declare(strict_types=1);

namespace Shopware\Core\System\Country\SalesChannel;

use OpenApi\Annotations as OA;
use Shopware\Core\Framework\Adapter\Cache\AbstractCacheTracer;
use Shopware\Core\Framework\Adapter\Cache\CacheValueCompressor;
use Shopware\Core\Framework\DataAbstractionLayer\Cache\EntityCacheKeyGenerator;
use Shopware\Core\Framework\DataAbstractionLayer\FieldSerializer\JsonFieldSerializer;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Annotation\Entity;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Routing\Annotation\Since;
use Shopware\Core\System\Country\Event\CountryRouteCacheKeyEvent;
use Shopware\Core\System\Country\Event\CountryRouteCacheTagsEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\StoreApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @RouteScope(scopes={"store-api"})
 */
class CachedCountryRoute extends AbstractCountryRoute
{
    public const ALL_TAG = 'country-route';

    private AbstractCountryRoute $decorated;

    private CacheInterface $cache;

    private EntityCacheKeyGenerator $generator;

    /**
     * @var AbstractCacheTracer<CountryRouteResponse>
     */
    private AbstractCacheTracer $tracer;

    private array $states;

    private EventDispatcherInterface $dispatcher;

    /**
     * @param AbstractCacheTracer<CountryRouteResponse> $tracer
     */
    public function __construct(
        AbstractCountryRoute $decorated,
        CacheInterface $cache,
        EntityCacheKeyGenerator $generator,
        AbstractCacheTracer $tracer,
        EventDispatcherInterface $dispatcher,
        array $states
    ) {
        $this->decorated = $decorated;
        $this->cache = $cache;
        $this->generator = $generator;
        $this->tracer = $tracer;
        $this->states = $states;
        $this->dispatcher = $dispatcher;
    }

    public static function buildName(string $id): string
    {
        return 'country-route-' . $id;
    }

    public function getDecorated(): AbstractCountryRoute
    {
        return $this->decorated;
    }

    /**
     * @Since("6.3.0.0")
     * @OA\Post(
     *      path="/country",
     *      summary="Fetch countries",
     *      description="Perform a filtered search for countries",
     *      operationId="readCountry",
     *      tags={"Store API", "System & Context"},
     *      @OA\Parameter(name="Api-Basic-Parameters"),
     *      @OA\Response(
     *          response="200",
     *          description="Entity search result containing countries.",
     *          @OA\JsonContent(
     *              type="object",
     *              allOf={
     *                  @OA\Schema(ref="#/components/schemas/EntitySearchResult"),
     *                  @OA\Schema(type="object",
     *                      @OA\Property(
     *                          type="array",
     *                          property="elements",
     *                          @OA\Items(ref="#/components/schemas/Country")
     *                      )
     *                  )
     *              }
     *          )
     *     )
     * )
     * @Entity("country")
     * @Route("/store-api/country", name="store-api.country", methods={"GET", "POST"})
     */
    public function load(Request $request, Criteria $criteria, SalesChannelContext $context): CountryRouteResponse
    {
        if ($context->hasState(...$this->states)) {
            return $this->getDecorated()->load($request, $criteria, $context);
        }

        $key = $this->generateKey($request, $context, $criteria);

        $value = $this->cache->get($key, function (ItemInterface $item) use ($request, $context, $criteria) {
            $name = self::buildName($context->getSalesChannelId());

            $response = $this->tracer->trace($name, function () use ($request, $context, $criteria) {
                return $this->getDecorated()->load($request, $criteria, $context);
            });

            $item->tag($this->generateTags($request, $response, $context, $criteria));

            return CacheValueCompressor::compress($response);
        });

        return CacheValueCompressor::uncompress($value);
    }

    private function generateKey(Request $request, SalesChannelContext $context, Criteria $criteria): string
    {
        $parts = [
            self::buildName($context->getSalesChannelId()),
            $this->generator->getCriteriaHash($criteria),
            $this->generator->getSalesChannelContextHash($context),
        ];

        $event = new CountryRouteCacheKeyEvent($parts, $request, $context, $criteria);
        $this->dispatcher->dispatch($event);

        return md5(JsonFieldSerializer::encodeJson($event->getParts()));
    }

    private function generateTags(Request $request, StoreApiResponse $response, SalesChannelContext $context, Criteria $criteria): array
    {
        $tags = array_merge(
            $this->tracer->get(self::buildName($context->getSalesChannelId())),
            [self::buildName($context->getSalesChannelId()), self::ALL_TAG]
        );

        $event = new CountryRouteCacheTagsEvent($tags, $request, $response, $context, $criteria);
        $this->dispatcher->dispatch($event);

        return array_unique(array_filter($event->getTags()));
    }
}
