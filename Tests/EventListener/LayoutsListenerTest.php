<?php

/*
* This file is part of the OrbitaleCmsBundle package.
*
* (c) Alexandre Rock Ancelet <alex@orbitale.io>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Orbitale\Bundle\CmsBundle\Tests\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Orbitale\Bundle\CmsBundle\Controller\CategoryController;
use Orbitale\Bundle\CmsBundle\Controller\PageController;
use Orbitale\Bundle\CmsBundle\Controller\PostsController;
use Orbitale\Bundle\CmsBundle\EventListener\LayoutsListener;
use Orbitale\Bundle\CmsBundle\Tests\AbstractTestCase;
use Orbitale\Bundle\CmsBundle\Tests\Fixtures\TestBundle\Entity\Page;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Twig\Environment;
use Twig\Error\LoaderError;

class LayoutsListenerTest extends AbstractTestCase
{
    public function testDifferentLayout(): void
    {
        $client = static::createClient(['environment' => 'layout']);

        /** @var Environment $twig */
        $twig = static::$container->get(Environment::class);
        $twig->resolveTemplate('test_layout.html.twig');

        $crawler = $client->request('GET', '/page/');

        static::assertEquals(1, $crawler->filter('#test_layout_wrapper')->count());
        static::assertRegExp('~^One change of the layout is this special hardcoded title\. ~', $crawler->filter('title')->html());
    }

    public function testHostLayout(): void
    {
        $client = static::createClient(['environment' => 'layout'], ['HTTP_HOST' => 'local.host']);

        $crawler = $client->request('GET', '/page/');

        static::assertRegExp('~^This layout is only for local\.host\. ~', $crawler->filter('title')->html());
    }

    public function testLayoutWrong(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to find template this_layout_does_not_exist.html.twig for layout front. The "layout" parameter must be a valid twig view to be used as a layout.');
        static::createClient(['environment' => 'layout_wrong'])->request('GET', '/page/');
    }

    public function testNoLayoutsDoesNotSetRequestAttribute()
    {
        $kernel = static::bootKernel();
        $request = Request::create('/');
        $listener = new LayoutsListener([], $this->getTwig());
        $listener->setRequestLayout(new RequestEvent($kernel, $request, $kernel::MASTER_REQUEST));
        static::assertFalse($request->attributes->has('_orbitale_cms_layout'));
    }

    public function testNoMatchingLayoutDoesNotSetRequestAttribute()
    {
        $kernel = static::bootKernel();

        $listener = new LayoutsListener([
            [
                'pattern' => '/noop',
                'host' => '',
                'resource' => '.',
            ],
        ], $this->getTwig());
        $request = Request::create('/');
        $listener->setRequestLayout(new RequestEvent($kernel, $request, $kernel::MASTER_REQUEST));

        static::assertFalse($request->attributes->has('_orbitale_cms_layout'));
    }

    /** @dataProvider provideBundleControllerClasses */
    public function testNoMatchingLayoutWithBundleControllerThrowsException(string $controller)
    {
        $kernel = static::bootKernel();

        $listener = new LayoutsListener([
            [
                'pattern' => '/noop',
                'host' => '',
                'resource' => '.',
            ],
        ], $this->getTwig());
        $request = Request::create('/no-way');
        $request->attributes->set('_controller', $controller);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to find layout for url "http://localhost/no-way". Did you forget to add a layout configuration for this path?');
        $listener->setRequestLayout(new RequestEvent($kernel, $request, $kernel::MASTER_REQUEST));
    }

    public function provideBundleControllerClasses(): \Generator
    {
        yield [PageController::class];
        yield [CategoryController::class];
        yield [PostsController::class];
    }

    /**
     * {@inheritdoc}
     */
    protected static function createClient(array $options = [], array $server = [])
    {
        $client = parent::createClient($options, $server);

        $homepage = new Page();

        $homepage->setHomepage(true);
        $homepage->setEnabled(true);
        $homepage->setSlug('home');
        $homepage->setTitle('My homepage');
        $homepage->setContent('Hello world!');

        /** @var EntityManagerInterface $em */
        $em = static::$container->get(EntityManagerInterface::class);
        $em->persist($homepage);
        $em->flush();

        return $client;
    }

    private function getTwig(): Environment
    {
        return $this->createMock(Environment::class);
    }
}
