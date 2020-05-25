<?php

/*
* This file is part of the OrbitaleCmsBundle package.
*
* (c) Alexandre Rock Ancelet <alex@orbitale.io>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Orbitale\Bundle\CmsBundle\EventListener;

use Orbitale\Bundle\CmsBundle\Controller\CategoryController;
use Orbitale\Bundle\CmsBundle\Controller\PageController;
use Orbitale\Bundle\CmsBundle\Controller\PostsController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Source;

class LayoutsListener implements EventSubscriberInterface
{
    /**
     * @var array
     */
    private $layouts;

    /**
     * @var Environment
     */
    private $twig;

    public function __construct(array $layouts, Environment $twig)
    {
        $this->layouts = $layouts;
        $this->twig    = $twig;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => ['setRequestLayout', 1],
        ];
    }

    public function setRequestLayout(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Get the necessary informations to check them in layout configurations
        $path = $request->getPathInfo();
        $host = $request->getHost();

        // As a layout must be set, we force it to be empty if no layout is properly configured.
        // Then this will throw an exception, and the user will be warned of the "no-layout" config problem.
        $finalLayout = null;

        foreach ($this->layouts as $layoutConfig) {
            $match = false;

            // First check host
            if ($layoutConfig['host'] && $host === $layoutConfig['host']) {
                $match = true;
            }

            // Check pattern
            if ($layoutConfig['pattern'] && preg_match('~'.$layoutConfig['pattern'].'~', $path)) {
                $match = true;
            }

            if ($match) {
                $finalLayout = $layoutConfig;
                break;
            }
        }

        // If nothing matches, we take the first layout that has no "host" or "pattern" configuration.
        if (null === $finalLayout) {
            $layouts = $this->layouts;
            do {
                $finalLayout = array_shift($layouts);
                if (!$finalLayout) {
                    continue;
                }
                if ($finalLayout['host'] || $finalLayout['pattern']) {
                    $finalLayout = null;
                }
            } while (null === $finalLayout && count($layouts));
        }

        if (null === $finalLayout) {
            // Means that there is no fall-back to "default layout".

            $controller = $request->attributes->get('_controller');

            if (
                !is_a($controller, PageController::class, true)
                && !is_a($controller, CategoryController::class, true)
                && !is_a($controller, PostsController::class, true)
            ) {
                // Don't do anything if there's no layout and the controller isn't supposed to use it.
                // If the user still wants to use a layout "outside" the built-in controllers,
                // they will have to add a layout config for it anyway.
                return;
            }

            throw new \RuntimeException(sprintf(
                'Unable to find layout for url "%s://%s%s". Did you forget to add a layout configuration for this path?',
                $request->getScheme(), $host, $path
            ));
        }

        if (!$this->twig->getLoader()->exists($finalLayout['resource'])) {
            throw new \RuntimeException(sprintf(
                'Unable to find template %s for layout %s. The "layout" parameter must be a valid twig view to be used as a layout.',
                $finalLayout['resource'] ?? '', $finalLayout['name'] ?? ''
            ));
        }


        $event->getRequest()->attributes->set('_orbitale_cms_layout', $finalLayout);
    }
}
