<?php

/*
* This file is part of the OrbitaleCmsBundle package.
*
* (c) Alexandre Rock Ancelet <alex@orbitale.io>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Orbitale\Bundle\CmsBundle\Tests\Entity;

use Doctrine\ORM\EntityManager;
use Orbitale\Bundle\CmsBundle\Tests\AbstractTestCase;
use Orbitale\Bundle\CmsBundle\Tests\Fixtures\TestBundle\Entity\Page;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;

class PageTest extends AbstractTestCase
{
    public function getDummyPage(): Page
    {
        $page = new Page();

        $page->setHomepage(true);
        $page->setSlug('home');
        $page->setTitle('My homepage');
        $page->setHost('localhost');
        $page->setContent('Hello world!');

        return $page;
    }

    public function testOneHomepage()
    {
        $homepage = $this->getDummyPage();

        $kernel = static::bootKernel();

        /** @var EntityManager $em */
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        $em->persist($homepage);
        $em->flush();

        /** @var Page $homepage */
        $homepage = $em->getRepository(get_class($homepage))->find($homepage->getId());

        static::assertEquals($homepage->getTitle(), (string) $homepage);

        static::assertFalse($homepage->isEnabled()); // Base value in entity
        static::assertTrue($homepage->isHomepage());
        static::assertEquals('localhost', $homepage->getHost());
        static::assertInstanceOf(\DateTimeImmutable::class, $homepage->getCreatedAt());

        $homepage->setParent($homepage);
        static::assertNull($homepage->getParent());
    }

    public function testLifecycleCallbacks()
    {
        $homepage = $this->getDummyPage();

        $child = $this->getDummyPage();
        $child->setSlug('child');

        $homepage->addChild($child);
        $child->setParent($homepage);

        $kernel = static::bootKernel();

        /** @var EntityManager $em */
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        $em->persist($homepage);
        $em->persist($child);
        $em->flush();

        static::assertEquals([$child], $homepage->getChildren()->toArray());

        /** @var Page $homepage */
        $homepage = $em->getRepository(get_class($homepage))->findOneBy(['id' => $homepage->getId()]);

        static::assertNotNull($homepage);

        if (null !== $homepage) {
            $em->remove($homepage);
            $em->flush();
        }

        $homepage = $em->getRepository(get_class($homepage))->findOneBy(['id' => $homepage->getId()]);

        static::assertNull($homepage);
        static::assertNull($child->getParent());
    }

    public function testRemoval()
    {
        $page = new Page();
        $page->setTitle('Default page');
        $page->setSlug('default');
        $page->setEnabled(true);

        $child = new Page();
        $child->setTitle('Child page');
        $child->setSlug('child');
        $child->setEnabled(true);
        $child->setParent($page);

        $page->addChild($child);

        $kernel = static::bootKernel();

        /** @var EntityManager $em */
        $em = $kernel->getContainer()->get('doctrine')->getManager();
        $em->persist($page);
        $em->persist($child);
        $em->flush();

        $page = $em->getRepository(get_class($page))->find($page->getId());

        $children = $page->getChildren();
        /** @var Page $first */
        $first = $children[0];
        static::assertEquals($child->getId(), $first->getId());

        $page->removeChild($child);
        $child->setParent(null);

        $em->remove($page);
        $em->flush();

        $child = $em->getRepository(get_class($child))->find($child->getId());

        static::assertNull($child->getParent());
    }

    public function testPageSlugIsTransliterated()
    {
        $page = new Page();
        $page->setTitle('Default page');

        $page->updateSlug();

        static::assertEquals('default-page', $page->getSlug());
    }

    public function testSuccessfulFormSubmissionWithEmptyData()
    {
        static::bootKernel();

        $page = new Page();

        /** @var FormBuilderInterface $builder */
        $builder = static::$container->get(FormFactoryInterface::class)->createBuilder(FormType::class, $page);
        $builder
            ->add('title')
            ->add('slug')
            ->add('metaDescription')
            ->add('metaTitle')
            ->add('metaKeywords')
            ->add('host')
            ->add('content', TextareaType::class)
            ->add('css', TextareaType::class)
            ->add('js', TextareaType::class)
            ->add('parent', EntityType::class, ['class' => Page::class])
            ->add('homepage', CheckboxType::class)
            ->add('enabled', CheckboxType::class)
        ;

        $form = $builder->getForm();

        $form->submit([
            'title' => null,
            'slug' => null,
            'metaDescription' => null,
            'metaTitle' => null,
            'metaKeywords' => null,
            'host' => null,
            'content' => null,
            'css' => null,
            'js' => null,
            'category' => null,
            'parent' => null,
            'homepage' => null,
            'enabled' => null,
        ]);

        static::assertSame('', $page->getTitle());
        static::assertSame('', $page->getSlug());
        static::assertNull($page->getMetaDescription());
        static::assertNull($page->getMetaTitle());
        static::assertNull($page->getMetaKeywords());
        static::assertNull($page->getHost());
        static::assertNull($page->getContent());
        static::assertNull($page->getCss());
        static::assertNull($page->getJs());
        static::assertNull($page->getCategory());
        static::assertNull($page->getParent());
        static::assertFalse($page->isHomepage());
        static::assertFalse($page->isEnabled());
    }
}
