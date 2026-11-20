<?php

declare(strict_types=1);

namespace Msstc4Symfony\MetricsBundle\Test\Integration\Kernel\Http;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Fragment\FragmentHandler;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class EsiTestController
{
    public const PAGE = '/_test/esi/page';

    public const FRAGMENT = '/_test/esi/fragment';

    public function __construct(
        private FragmentHandler $fragments,
    ) {
    }

    // Cacheable page with an uncacheable fragment: the second request is a page hit and a fragment miss.
    #[Route(self::PAGE, name: 'test_esi_page', methods: 'GET')]
    public function page(): Response
    {
        $response = new Response('page:' . $this->fragments->render(self::FRAGMENT, 'esi'));
        $response->setPublic();
        $response->setSharedMaxAge(60);

        return $response;
    }

    #[Route(self::FRAGMENT, name: 'test_esi_fragment', methods: 'GET')]
    public function fragment(): Response
    {
        return new Response('fragment');
    }
}
