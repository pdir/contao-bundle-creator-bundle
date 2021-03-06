<?= "<?php\n" ?>

declare(strict_types=1);

<?= $this->phpdoc ?>

namespace <?= $this->toplevelnamespace ?>\<?= $this->sublevelnamespace ?>\ContaoManager;

use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
<?php if($this->addCustomRoute == "1"): ?>
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
<?php endif; ?>
<?php if($this->addCustomRoute == "1"): ?>
use Symfony\Component\Config\Loader\LoaderResolverInterface;
<?php endif; ?>
<?php if($this->addCustomRoute == "1"): ?>
use Symfony\Component\HttpKernel\KernelInterface;
<?php endif; ?>

/**
 * Class Plugin
 */
class Plugin implements BundlePluginInterface<?php if($this->addCustomRoute == "1"): ?>, RoutingPluginInterface<?php endif; ?><?= "\n" ?>
{
    /**
     * @return array
     */
    public function getBundles(ParserInterface $parser)
    {
        return [
            BundleConfig::create('<?= $this->toplevelnamespace ?>\<?= $this->sublevelnamespace ?>\<?= $this->toplevelnamespace ?><?= $this->sublevelnamespace ?>')
                ->setLoadAfter(['Contao\CoreBundle\ContaoCoreBundle']),
        ];
    }
<?php if($this->addCustomRoute == "1"): ?>

    /**
     * @return null|\Symfony\Component\Routing\RouteCollection
     * @throws \Exception
     */
    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel)
    {
        return $resolver
            ->resolve(__DIR__ . '/../Resources/config/routes.yml')
            ->load(__DIR__ . '/../Resources/config/routes.yml');
    }
<?php endif; ?>
}
