<?php
/*
* This file is part of the OrbitaleCmsBundle package.
*
* (c) Alexandre Rock Ancelet <alex@orbitale.io>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Orbitale\Bundle\CmsBundle\Tests\Controller;

use Doctrine\ORM\EntityManager;
use Orbitale\Bundle\CmsBundle\Entity\Page;
use Orbitale\Bundle\CmsBundle\Tests\Fixtures\AbstractTestCase;

class PageControllerTest extends AbstractTestCase
{

    public function testNoHomepage()
    {
        $error = 'No homepage has been configured. Please check your existing pages or create a homepage in your application. (404 Not Found)';
        $client = static::createClient();
        $crawler = $client->request('GET', '/page/');
        $this->assertEquals($error, trim($crawler->filter('title')->html()));
        $this->assertEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testNoPageWithSlug()
    {
        $client = static::createClient();
        $client->request('GET', '/page/inexistent-slug');
        $this->assertEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testOneHomepage()
    {
        $client = static::createClient();

        $homepage = $this->createPage(array(
            'homepage' => true,
            'enabled' => true,
            'slug' => 'home',
            'title' => 'My homepage',
            'host' => 'localhost',
            'content' => 'Hello world!',
        ));

        /** @var EntityManager $em */
        $em = $client->getKernel()->getContainer()->get('doctrine')->getManager();
        $em->persist($homepage);
        $em->flush();

        $crawler = $client->request('GET', '/page/');
        $this->assertEquals($homepage->getTitle(), trim($crawler->filter('title')->html()));
        $this->assertEquals($homepage->getTitle(), trim($crawler->filter('article > h1')->html()));
        $this->assertContains($homepage->getContent(), trim($crawler->filter('article')->html()));

        // Repeat with the homepage directly in the url

        // First, check that any right trimming "/" will redirect
        $client->request('GET', '/page/home/');
        $this->assertTrue($client->getResponse()->isRedirect('/page/home'));

        $crawler = $client->followRedirect();

        $this->assertEquals($homepage->getTitle(), trim($crawler->filter('title')->html()));
        $this->assertEquals($homepage->getTitle(), trim($crawler->filter('article > h1')->html()));
        $this->assertContains($homepage->getContent(), trim($crawler->filter('article')->html()));
    }

    public function testTree()
    {
        $client = static::createClient();

        /** @var EntityManager $em */
        $em = $client->getKernel()->getContainer()->get('doctrine')->getManager();

        // Prepare 3 pages : the root, the first level, and the third one that's disabled
        $parent = $this->createPage(array(
            'homepage' => true,
            'enabled' => true,
            'slug' => 'root',
            'title' => 'Root',
            'content' => 'The root page',
            'deletedAt' => null,
        ));
        $em->persist($parent);
        $em->flush();

        $childOne = $this->createPage(array(
            'enabled' => true,
            'slug' => 'first-level',
            'title' => 'First level',
            'content' => 'This page is only available in the first level',
            'parent' => $em->find(get_class($parent), $parent),
            'deletedAt' => null,
        ));
        $em->persist($childOne);
        $em->flush();

        $childTwoDisabled = $this->createPage(array(
            'enabled' => false,
            'slug' => 'second-level',
            'title' => 'Disabled Page',
            'content' => 'This page should render a 404 error',
            'parent' => $em->find(get_class($parent), $parent),
            'deletedAt' => null,
        ));
        $em->persist($childTwoDisabled);
        $em->flush();

        // Repeat with the homepage directly in the url
        $crawler = $client->request('GET', '/page/'.$childOne->getTree());
        $this->assertEquals($childOne->getTitle(), trim($crawler->filter('title')->html()));
        $this->assertEquals($childOne->getTitle(), trim($crawler->filter('article > h1')->html()));
        $this->assertContains($childOne->getContent(), trim($crawler->filter('article')->html()));

        // Repeat with the homepage directly in the url
        $client->request('GET', '/page/root/second-level');
        $this->assertEquals(404, $client->getResponse()->getStatusCode());
    }

    public function testMetas()
    {
        $client = static::createClient();

        /** @var EntityManager $em */
        $em = $client->getKernel()->getContainer()->get('doctrine')->getManager();

        $page = $this->createPage(array(
            'homepage' => true,
            'enabled' => true,
            'title' => 'Root',
            'content' => 'The root page',
            'css' => '#home{color:red;}',
            'js' => 'alert("ok");',
            'metaDescription' => 'meta description',
            'metaKeywords' => 'this is a meta keyword list',
            'metaTitle' => 'this title is only in the metas',
        ));
        $em->persist($page);
        $em->flush();

        $crawler = $client->request('GET', '/page/'.$page->getTree());
        $this->assertEquals($page->getTitle(), trim($crawler->filter('title')->html()));
        $this->assertEquals($page->getTitle(), trim($crawler->filter('article > h1')->html()));
        $this->assertContains($page->getContent(), trim($crawler->filter('article')->html()));

        $this->assertEquals($page->getCss(), trim($crawler->filter('#orbitale_cms_css')->html()));
        $this->assertEquals($page->getJs(), trim($crawler->filter('#orbitale_cms_js')->html()));
        $this->assertEquals($page->getMetaDescription(), trim($crawler->filter('meta[name="description"]')->attr('content')));
        $this->assertEquals($page->getMetaKeywords(), trim($crawler->filter('meta[name="keywords"]')->attr('content')));
        $this->assertEquals($page->getMetaTitle(), trim($crawler->filter('meta[name="title"]')->attr('content')));

    }

    public function testParentAndChildrenDontReverse()
    {
        $client = static::createClient();
        /** @var EntityManager $em */
        $em = $client->getKernel()->getContainer()->get('doctrine')->getManager();

        $parent = $this->createPage(array('enabled' => true, 'homepage' => true, 'title' => 'Locale+host', 'host' => 'localhost', 'locale' => 'en'));
        $em->persist($parent);
        $em->flush();

        $child = $this->createPage(array('enabled' => true, 'homepage' => true, 'title' => 'Host only', 'host' => 'localhost', 'parent' => $parent));
        $em->persist($child);
        $em->flush();

        $client->request('GET', '/page/'.$child->getSlug().'/'.$parent->getSlug());
        $this->assertEquals(404, $client->getResponse()->getStatusCode());

    }

    /**
     * With the locale & host matching system, the precedence of the homepage should have this order (the first being the most important):
     * - Locale & Host
     * - Host only
     * - Locale only
     * - No matching criteria
     * If there are multiple pages that match any "matching criteria", the behavior is unexpected, so we should not handle this naturally.
     */
    public function testAllTypesOfPagesForHomepage()
    {
        $client = static::createClient();

        /** @var EntityManager $em */
        $em = $client->getKernel()->getContainer()->get('doctrine')->getManager();

        // First, create the pages
        /** @var Page[] $pages */
        $pages = array(
            'both'   => $this->createPage(array('enabled' => true, 'homepage' => true, 'title' => 'Locale+host', 'host' => 'localhost', 'locale' => 'en')),
            'host'   => $this->createPage(array('enabled' => true, 'homepage' => true, 'title' => 'Host only', 'host' => 'localhost')),
            'locale' => $this->createPage(array('enabled' => true, 'homepage' => true, 'title' => 'Locale only', 'locale' => 'en')),
            'none'   => $this->createPage(array('enabled' => true, 'homepage' => true, 'title' => 'No match')),
        );
        foreach ($pages as $page) {
            $em->persist($page);
        }
        $em->flush();

        // Loop the pages because the "$pages" array respects precedence,
        // So disabling the pages on each loop should make all assertions work.
        foreach ($pages as $key => $page) {
            $crawler = $client->request('GET', '/page/');
            $this->assertEquals($page->getTitle(), trim($crawler->filter('title')->html()));
            $page->setEnabled(false);
            $em->merge($page);
            $em->flush();
        }
    }
}
