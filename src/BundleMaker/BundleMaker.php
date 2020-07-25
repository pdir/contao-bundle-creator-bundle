<?php

/**
 * @copyright  Marko Cupic 2020 <m.cupic@gmx.ch>
 * @author     Marko Cupic
 * @package    Contao Bundle Creator
 * @license    MIT
 * @see        https://github.com/markocupic/contao-bundle-creator-bundle
 *
 */

declare(strict_types=1);

namespace Markocupic\ContaoBundleCreatorBundle\BundleMaker;

use Contao\Date;
use Contao\File;
use Contao\Files;
use Contao\StringUtil;
use Markocupic\ContaoBundleCreatorBundle\BundleMaker\Storage\FileStorage;
use Markocupic\ContaoBundleCreatorBundle\BundleMaker\Storage\TagStorage;
use Markocupic\ContaoBundleCreatorBundle\BundleMaker\Message\Message;
use Markocupic\ContaoBundleCreatorBundle\BundleMaker\SimpleToken\SimpleTokenParser;
use Markocupic\ContaoBundleCreatorBundle\Model\ContaoBundleCreatorModel;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Class BundleMaker
 * @package Markocupic\ContaoBundleCreatorBundle\BundleMaker
 */
class BundleMaker
{
    /** @var SessionInterface */
    protected $session;

    /** @var FileStorage */
    protected $fileStorage;

    /** @var TagStorage */
    protected $tagStorage;

    /** @var Message */
    protected $message;

    /** @var string */
    protected $projectDir;

    /** @var ContaoBundleCreatorModel */
    protected $model;

    /** @var string */
    const SAMPLE_DIR = 'vendor/markocupic/contao-bundle-creator-bundle/src/Resources/skeleton/sample-repository';

    /**
     * BundleMaker constructor.
     *
     * @param Session $session
     * @param FileStorage $fileStorage
     * @param TagStorage $tagStorage
     * @param Message $message
     * @param string $projectDir
     */
    public function __construct(Session $session, FileStorage $fileStorage, TagStorage $tagStorage, Message $message, string $projectDir)
    {
        $this->session = $session;
        $this->fileStorage = $fileStorage;
        $this->tagStorage = $tagStorage;
        $this->message = $message;
        $this->projectDir = $projectDir;
    }

    /**
     * Run contao bundle creator
     *
     * @param ContaoBundleCreatorModel $model
     * @throws \Exception
     */
    public function run(ContaoBundleCreatorModel $model): void
    {
        $this->model = $model;

        if ($this->bundleExists() && !$this->model->overwriteexisting)
        {
            $this->message->addError('An extension with the same name already exists. Please set the "override extension flag".');
            return;
        }

        $this->message->addInfo(sprintf('Started generating "%s/%s" bundle.', $this->model->vendorname, $this->model->repositoryname));

        // Sanitize model (backendmoduletype, backendmodulecategory, frontendmoduletype, frontendmodulecategory)
        // Don't move the position, this has to be called first!
        $this->sanitizeModel();

        // Set the tags (##****##)
        $this->setTags();

        // Add the composer.json file to file storage
        $this->addComposerJsonFileToFileStorage();

        // Add the bundle class to file storage
        $this->addBundleClassToFileStorage();

        // Add the Contao Manager Plugin class to file storage
        $this->addContaoManagerPluginClassToFileStorage();

        // Config files, assets, etc.
        $this->addMiscFilesToFileStorage();

        // Add backend module files to file storage
        if ($this->model->addBackendModule && $this->model->dcatable != '')
        {
            $this->addBackendModuleFilesToFileStorage();
        }

        // Add frontend module files to file storage
        if ($this->model->addFrontendModule)
        {
            $this->addFrontendModuleFilesToFileStorage();
        }

        // Add a custom route to the file storage
        if ($this->model->addCustomRoute)
        {
            $this->addCustomRouteToFileStorage();
        }

        // Create a backup of the old bundle that will be overwritten now
        if ($this->bundleExists())
        {
            $zipSource = sprintf('vendor/%s/%s', $this->model->vendorname, $this->model->repositoryname);
            $zipTarget = sprintf('system/tmp/%s.zip', $this->model->repositoryname . '_backup_' . Date::parse('Y-m-d _H-i-s', time()));
            $this->zipData($zipSource, $zipTarget);
        }

        // Replace if-tokens and replace simple tokens in file storage
        $this->replaceTokens();

        // Create all the bundle files in vendor/vendorname/bundlename
        $this->createFilesFromFileStorage();

        // Store new bundle also as a zip-package for downloading it from system/tmp
        $zipSource = sprintf('vendor/%s/%s', $this->model->vendorname, $this->model->repositoryname);
        $zipTarget = sprintf('system/tmp/%s.zip', $this->model->repositoryname);
        if ($this->zipData($zipSource, $zipTarget))
        {
            $this->session->set('CONTAO-BUNDLE-CREATOR.LAST-ZIP', $zipTarget);
        }

        // Optionally extend the composer.json file located in the root directory
        $this->editRootComposerJson();
    }

    /**
     * Check if an extension with the same name already exists
     *
     * @return bool
     */
    protected function bundleExists(): bool
    {
        return is_dir($this->projectDir . '/vendor/' . $this->model->vendorname . '/' . $this->model->repositoryname);
    }

    /**
     * Sanitize model
     *
     * @throws \Exception
     */
    protected function sanitizeModel(): void
    {
        if ($this->model->vendorname != '')
        {
            // Sanitize vendorname (github restrictions)
            // Do no allow: vendor_name, -vendorname, vendorname-, vendor--name
            // But allow Vendor-Name, vendor-name
            $this->model->vendorname = preg_replace('/[\-]{2,}/', '-', $this->model->vendorname);
            $this->model->vendorname = preg_replace('/^\-+|_+|[^A-Za-z0-9\-]|\-+$/', '', $this->model->vendorname);
            $this->model->save();
        }

        if ($this->model->repositoryname != '')
        {
            // Sanitize repositoryname
            $this->model->repositoryname = preg_replace('/[^A-Za-z0-9_\-]/', '-', $this->model->repositoryname);
            $this->model->save();
        }

        if ($this->model->backendmoduletype != '')
        {
            // Get the backend module type and sanitize it to the contao backend module convention
            $this->model->backendmoduletype = $this->getSanitizedBackendModuleType();
            $this->model->save();
        }

        if ($this->model->dcatable != '')
        {
            $this->model->dcatable = $this->getSanitizedDcaTableName();
            $this->model->save();
        }

        if ($this->model->backendmodulecategory != '')
        {
            // Get the backend module category and sanitize it to the contao backend module convention
            $this->model->backendmodulecategory = $this->toSnakecase((string) $this->model->backendmodulecategory);
            $this->model->save();
        }

        if ($this->model->frontendmoduletype != '')
        {
            // Get the frontend module type and sanitize it to the contao frontend module convention
            $this->model->frontendmoduletype = $this->getSanitizedFrontendModuleType();
            $this->model->save();
        }

        if ($this->model->frontendmodulecategory != '')
        {
            // Get the frontend module category and sanitize it to the contao frontend module convention
            $this->model->frontendmodulecategory = $this->toSnakecase((string) $this->model->frontendmodulecategory);
            $this->model->save();
        }
    }

    /**
     * Set all the tags here
     *
     * @todo add a contao hook
     * @throws \Exception
     */
    protected function setTags(): void
    {
        // Store model values into the tag storage
        $arrModel = $this->model->row();
        foreach ($arrModel as $fieldname => $value)
        {
            $this->tagStorage->add((string) $fieldname, (string) $value);
        }

        // Tags
        $this->tagStorage->add('vendorname', (string) $this->model->vendorname);
        $this->tagStorage->add('repositoryname', (string) $this->model->repositoryname);
        $this->tagStorage->add('vendornametolower', (string) str_replace('-', '_', strtolower($this->model->vendorname)));
        $this->tagStorage->add('repositorynametolower', (string) preg_replace('/\-bundle$/', '', str_replace('-', '_', strtolower($this->model->repositoryname))));

        // Namespaces
        $this->tagStorage->add('toplevelnamespace', $this->toPsr4Namespace((string) $this->model->vendorname));
        $this->tagStorage->add('sublevelnamespace', $this->toPsr4Namespace((string) $this->model->repositoryname));

        // Twig namespace @Vendor/Bundlename
        $this->tagStorage->add('toplevelnamespacetwig', preg_replace('/Bundle$/', '', '@' . $this->toPsr4Namespace((string) $this->model->vendorname) . $this->toPsr4Namespace((string) $this->model->repositoryname)));

        // Composer
        $this->tagStorage->add('composerdescription', (string) $this->model->composerdescription);
        $this->tagStorage->add('composerlicense', (string) $this->model->composerlicense);
        $this->tagStorage->add('composerauthorname', (string) $this->model->composerauthorname);
        $this->tagStorage->add('composerauthoremail', (string) $this->model->composerauthoremail);
        $this->tagStorage->add('composerauthorwebsite', (string) $this->model->composerauthorwebsite);

        // Phpdoc
        $this->tagStorage->add('bundlename', (string) $this->model->bundlename);
        $this->tagStorage->add('phpdoc', $this->getContentFromPartialFile('phpdoc.tpl.txt'));
        $this->tagStorage->add('year', date('Y'));

        // Dca table and backend module
        if ($this->model->addBackendModule && $this->model->dcatable != '')
        {
            $this->tagStorage->add('dcatable', (string) $this->model->dcatable);
            $this->tagStorage->add('modelclassname', (string) $this->getModelClassname());
            $this->tagStorage->add('backendmoduletype', (string) $this->model->backendmoduletype);
            $this->tagStorage->add('backendmodulecategory', (string) $this->model->backendmodulecategory);
            $arrLabel = StringUtil::deserialize($this->model->backendmoduletrans, true);
            $this->tagStorage->add('backendmoduletrans_0', $arrLabel[0]);
            $this->tagStorage->add('backendmoduletrans_1', $arrLabel[1]);
        }

        // Frontend module
        if ($this->model->addFrontendModule)
        {
            $this->tagStorage->add('frontendmoduleclassname', $this->getSanitizedFrontendModuleClassname());
            $this->tagStorage->add('frontendmoduletype', (string) $this->model->frontendmoduletype);
            $this->tagStorage->add('frontendmodulecategory', (string) $this->model->frontendmodulecategory);
            $this->tagStorage->add('frontendmoduletemplate', $this->getFrontendModuleTemplateName());
            $arrLabel = StringUtil::deserialize($this->model->frontendmoduletrans, true);
            $this->tagStorage->add('frontendmoduletrans_0', $arrLabel[0]);
            $this->tagStorage->add('frontendmoduletrans_1', $arrLabel[1]);
        }

        // Custom route
        if ($this->model->addCustomRoute)
        {
            $this->tagStorage->add('addcustomroute', '1');
        }
        else
        {
            $this->tagStorage->add('addcustomroute', '0');
        }
    }

    /**
     * Add composer.json file to file storage
     *
     * @throws \Exception
     */
    protected function addComposerJsonFileToFileStorage(): void
    {
        $blnModified = false;

        $source = self::SAMPLE_DIR . '/composer.tpl.json';
        $target = sprintf('vendor/%s/%s/composer.json', $this->model->vendorname, $this->model->repositoryname);
        $this->fileStorage->createFile($source, $target);

        // Add/remove version keyword from composer.json
        $content = $this->fileStorage->getContent();
        $composer = json_decode($content);

        if (isset($composer->version))
        {
            unset($composer->version);
            $blnModified = true;
        }

        if ($this->model->composerpackageversion == '')
        {
            if (isset($composer->version))
            {
                unset($composer->version);
                $blnModified = true;
            }
        }
        else
        {
            $composer->version = $this->model->composerpackageversion;
            $blnModified = true;
        }

        if ($blnModified)
        {
            $content = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->fileStorage->replaceContent($content);
        }
    }

    /**
     * Add the bundle class to file storage
     *
     * @throws \Exception
     */
    protected function addBundleClassToFileStorage(): void
    {
        $source = self::SAMPLE_DIR . '/src/BundleFile.tpl.php';
        $target = sprintf('vendor/%s/%s/src/%s%s.php', $this->model->vendorname, $this->model->repositoryname, $this->toPsr4Namespace((string) $this->model->vendorname), $this->toPsr4Namespace((string) $this->model->repositoryname));
        $this->fileStorage->createFile($source, $target);
    }

    /**
     * Add the Contao Manager plugin class to file storage
     *
     * @throws \Exception
     */
    protected function addContaoManagerPluginClassToFileStorage(): void
    {
        $source = self::SAMPLE_DIR . '/src/ContaoManager/Plugin.tpl.php';
        $target = sprintf('vendor/%s/%s/src/ContaoManager/Plugin.php', $this->model->vendorname, $this->model->repositoryname);
        $this->fileStorage->createFile($source, $target);
    }

    /**
     * Add custom route to the file storage
     *
     * @throws \Exception
     */
    protected function addCustomRouteToFileStorage(): void
    {
        // Add controller
        $source = self::SAMPLE_DIR . '/src/Controller/MyCustomController.tpl.php';
        $target = sprintf('vendor/%s/%s/src/Controller/MyCustomController.php', $this->model->vendorname, $this->model->repositoryname);
        $this->fileStorage->createFile($source, $target);

        // Add twig template
        $source = self::SAMPLE_DIR . '/src/Resources/views/my_custom_route.html.tpl.twig';
        $target = sprintf('vendor/%s/%s/src/Resources/views/my_custom_route.html.twig', $this->model->vendorname, $this->model->repositoryname);
        $this->fileStorage->createFile($source, $target);

        // Add src/Resources/config/services.yml
        $content = $this->getContentFromPartialFile('config_services_custom_frontend_route.tpl.yml');
        $target = sprintf('vendor/%s/%s/src/Resources/config/services.yml', $this->model->vendorname, $this->model->repositoryname);
        $this->fileStorage->getFile($target)->appendContent($content);
        try
        {
            // Validate yaml file
            $arrYaml = Yaml::parse($this->fileStorage->getContent());
            if (!array_key_exists('services', $arrYaml))
            {
                throw new ParseException('Key "services" not found. Please check the indents.');
            }
        } catch (ParseException $exception)
        {
            throw new ParseException(sprintf('Unable to parse the YAML string in %s: %s', $target, $exception->getMessage()));
        }
    }

    /**
     * Add backend module files to file storage
     *
     * @throws \Exception
     */
    protected function addBackendModuleFilesToFileStorage(): void
    {
        // Add dca table file
        $source = self::SAMPLE_DIR . '/src/Resources/contao/dca/tl_sample_table.tpl.php';
        $target = sprintf('vendor/%s/%s/src/Resources/contao/dca/%s.php', $this->model->vendorname, $this->model->repositoryname, $this->model->dcatable);
        $this->fileStorage->createFile($source, $target);

        // Add dca table translation file
        $source = self::SAMPLE_DIR . '/src/Resources/contao/languages/en/tl_sample_table.tpl.php';
        $target = sprintf('vendor/%s/%s/src/Resources/contao/languages/en/%s.php', $this->model->vendorname, $this->model->repositoryname, $this->model->dcatable);
        $this->fileStorage->createFile($source, $target);

        // Append data to src/Resources/contao/config/config.php
        $target = sprintf('vendor/%s/%s/src/Resources/contao/config/config.php', $this->model->vendorname, $this->model->repositoryname);
        $this->fileStorage->getFile($target)->appendContent($this->getContentFromPartialFile('contao_config_be_mod.tpl.php'));

        // Add language array to contao/languages/en/modules.php
        $content = $this->getContentFromPartialFile('contao_lang_en_be_modules.tpl.php');
        $target = sprintf('vendor/%s/%s/src/Resources/contao/languages/en/modules.php', $this->model->vendorname, $this->model->repositoryname);
        $this->fileStorage->getFile($target)->appendContent($content);

        // Add a sample model
        $source = self::SAMPLE_DIR . '/src/Model/SampleModel.php';
        $target = sprintf('vendor/%s/%s/src/Model/%s.php', $this->model->vendorname, $this->model->repositoryname, $this->getModelClassname());
        $this->fileStorage->createFile($source, $target);
        // Append data to src/Resources/contao/config/config.php
        $target = sprintf('vendor/%s/%s/src/Resources/contao/config/config.php', $this->model->vendorname, $this->model->repositoryname);
        $this->fileStorage->getFile($target)->appendContent($this->getContentFromPartialFile('contao_config_model.tpl.php'));
    }

    /**
     * Add frontend module files to file storage
     *
     * @throws \Exception
     */
    protected function addFrontendModuleFilesToFileStorage(): void
    {
        // Get the frontend module template name
        $strFrontenModuleTemplateName = $this->getFrontendModuleTemplateName();

        // Get the frontend module classname
        $strFrontendModuleClassname = $this->getSanitizedFrontendModuleClassname();

        // Add frontend module class to src/Controller/FrontendController
        $source = self::SAMPLE_DIR . '/src/Controller/FrontendModule/SampleModule.tpl.php';
        $target = sprintf('vendor/%s/%s/src/Controller/FrontendModule/%s.php', $this->model->vendorname, $this->model->repositoryname, $strFrontendModuleClassname);
        $this->fileStorage->createFile($source, $target);

        // Add src/Resources/contao/dca/tl_module.php
        $source = self::SAMPLE_DIR . '/src/Resources/contao/dca/tl_module.tpl.php';
        $target = sprintf('vendor/%s/%s/src/Resources/contao/dca/tl_module.php', $this->model->vendorname, $this->model->repositoryname);
        $this->fileStorage->createFile($source, $target)->appendContent($this->getContentFromPartialFile('contao_tl_module.tpl.php'));

        // Add frontend module template
        $source = self::SAMPLE_DIR . '/src/Resources/contao/templates/mod_sample.tpl.html5';
        $target = sprintf('vendor/%s/%s/src/Resources/contao/templates/%s.html5', $this->model->vendorname, $this->model->repositoryname, $strFrontenModuleTemplateName);
        $this->fileStorage->createFile($source, $target);

        // Add src/Resources/config/services.yml
        $content = $this->getContentFromPartialFile('config_services_frontend_modules.tpl.yml');
        $target = sprintf('vendor/%s/%s/src/Resources/config/services.yml', $this->model->vendorname, $this->model->repositoryname);
        $this->fileStorage->getFile($target)->appendContent($content);
        try
        {
            // Validate yaml file
            $arrYaml = Yaml::parse($this->fileStorage->getContent());
            if (!array_key_exists('services', $arrYaml))
            {
                throw new ParseException('Key "services" not found. Please check the indents.');
            }
        } catch (ParseException $exception)
        {
            throw new ParseException(sprintf('Unable to parse the YAML string in %s: %s', $target, $exception->getMessage()));
        }

        // Add language array to contao/languages/en/modules.php
        $content = $this->getContentFromPartialFile('contao_lang_en_fe_modules.tpl.php');
        $target = sprintf('vendor/%s/%s/src/Resources/contao/languages/en/modules.php', $this->model->vendorname, $this->model->repositoryname);
        $this->fileStorage->getFile($target)->appendContent($content);
    }

    /**
     * Add miscellaneous files to file storage
     *
     * @throws \Exception
     */
    protected function addMiscFilesToFileStorage(): void
    {
        // src/Resources/config/*.yml yaml config files
        $arrFiles = ['listener.tpl.yml', 'parameters.tpl.yml', 'services.tpl.yml'];

        if ($this->model->addCustomRoute)
        {
            $arrFiles[] = 'routes.tpl.yml';
        }

        foreach ($arrFiles as $file)
        {
            $source = sprintf('%s/src/Resources/config/%s', self::SAMPLE_DIR, $file);
            $target = sprintf('vendor/%s/%s/src/Resources/config/%s', $this->model->vendorname, $this->model->repositoryname, str_replace('tpl.', '', $file));
            $this->fileStorage->createFile($source, $target)->replaceTags($this->tagStorage);

            // Validate config files
            try
            {
                $arrYaml = Yaml::parse($this->fileStorage->getContent());
                if ($file === 'listener.tpl.yml' || $file === 'services.tpl.yml')
                {
                    if (!array_key_exists('services', $arrYaml))
                    {
                        throw new ParseException('Key "services" not found. Please check the indents.');
                    }
                }

                if ($file === 'parameters.tpl.yml')
                {
                    if (!array_key_exists('parameters', $arrYaml))
                    {
                        throw new ParseException('Key "parameters" not found. Please check the indents.');
                    }
                }
            } catch (ParseException $exception)
            {
                throw new ParseException(sprintf('Unable to parse the YAML string in %s: %s', $target, $exception->getMessage()));
            }
        }

        // src/Resource/contao/config/config.php
        $source = sprintf('%s/src/Resources/contao/config/config.tpl.php', self::SAMPLE_DIR);
        $target = sprintf('vendor/%s/%s/src/Resources/contao/config/config.php', $this->model->vendorname, $this->model->repositoryname);
        $this->fileStorage->createFile($source, $target);

        // src/Resource/contao/languages/en/modules.php
        $source = sprintf('%s/src/Resources/contao/languages/en/modules.tpl.php', self::SAMPLE_DIR);
        $target = sprintf('vendor/%s/%s/src/Resources/contao/languages/en/modules.php', $this->model->vendorname, $this->model->repositoryname);
        $this->fileStorage->createFile($source, $target);

        // Add logo
        $source = sprintf('%s/src/Resources/public/logo.tpl.png', self::SAMPLE_DIR);
        $target = sprintf('vendor/%s/%s/src/Resources/public/logo.png', $this->model->vendorname, $this->model->repositoryname);
        $this->fileStorage->createFile($source, $target);

        // Readme.md
        $source = sprintf('%s/README.tpl.md', self::SAMPLE_DIR);
        $target = sprintf('vendor/%s/%s/README.md', $this->model->vendorname, $this->model->repositoryname);
        $this->fileStorage->createFile($source, $target);
    }

    /**
     * Replace some special tags and return content from partials
     *
     * @param string $strFilename
     * @return string
     * @throws \Exception
     */
    protected function getContentFromPartialFile(string $strFilename): string
    {
        $sourceFile = self::SAMPLE_DIR . '/partials/' . $strFilename;

        if (!is_file($this->projectDir . '/' . $sourceFile))
        {
            throw new FileNotFoundException(sprintf('Partial file "%s" not found.', $sourceFile));
        }

        /** @var File $objPartialFile */
        $objPartialFile = new File($sourceFile);
        $content = $objPartialFile->getContent();
        $arrTags = $this->tagStorage->getAll();
        $content = SimpleTokenParser::parseSimpleTokens($content, $arrTags);

        return $content;
    }

    /**
     * Converts a string to namespace
     * "my_custom name-space" will become "MyCustomNameSpace"
     *
     * @param string $str
     * @return string
     */
    protected function toPsr4Namespace(string $str): string
    {
        $str = str_replace('/[^A-Za-z0-9_\-]/', '', $str);
        $str = str_replace('-', '_', $str);
        $str = str_replace(' ', '_', $str);
        // Trim from underscores
        $str = preg_replace('/^_|_$/', '', $str);
        // Do not allow multiple underscores in series
        $str = preg_replace('/_{2,}/', '_', $str);

        $arrNamespace = explode('_', $str);
        $arrNamespace = array_filter($arrNamespace, 'strlen');
        $arrNamespace = array_map('strtolower', $arrNamespace);
        $arrNamespace = array_map('ucfirst', $arrNamespace);
        $strBundleNamespace = implode('', $arrNamespace);

        return $strBundleNamespace;
    }

    /**
     * Converts a string to snakecase
     * My custom module => my_custom_module
     *
     * @param string $str
     * @return string
     */
    protected function toSnakecase(string $str): string
    {
        $str = str_replace('/[^A-Za-z0-9_\-]/', '', $str);
        $str = str_replace(' ', '_', $str);
        $str = str_replace('-', '_', $str);
        // Trim from underscores
        $str = preg_replace('/^_|_$/', '', $str);
        // Do not allow multiple underscores in series
        $str = preg_replace('/_{2,}/', '_', $str);
        $str = strtolower($str);

        return $str;
    }

    /**
     * Get the frontend module type (f.ex. my_custom_module)
     * Convention => snakecase with postfix "_module"
     *
     * @param string $postfix
     * @return string
     */
    protected function getSanitizedFrontendModuleType($postfix = '_module'): string
    {
        $str = $this->toSnakecase((string) $this->model->frontendmoduletype);

        $pattern = '/^(module_|module|mod_|mod)/';
        if (preg_match($pattern, $str))
        {
            $str = preg_replace($pattern, '', $str);
        }

        $pattern = '/(_module|module)$/';
        if (preg_match($pattern, $str))
        {
            $str = preg_replace($pattern, '', $str);
        }

        // Add postfix
        $str = $str . $postfix;

        return $str;
    }

    /**
     * Get the backend module type (f.ex. my_custom_module)
     * Convention => snakecase
     *
     * @return string
     */
    protected function getSanitizedBackendModuleType(): string
    {
        $str = $this->toSnakecase((string) $this->model->backendmoduletype);
        return $str;
    }

    /**
     * Get the sanitized dca tablename f.ex. tl_sample_table
     *
     * @return string
     * @throws \Exception
     */
    protected function getSanitizedDcaTableName(): string
    {
        if (!strlen((string) $this->model->dcatable))
        {
            throw new \Exception('No dca tablename set.');
        }

        $str = strtolower($this->model->dcatable);
        $str = preg_replace('/\-/', '_', $str);
        $str = preg_replace('/_{2,}/', '_', $str);
        $str = preg_replace('/[^A-Za-z0-9_]|_$/', '', $str);
        if (!preg_match('/^tl_/', $str))
        {
            $str = 'tl_' . $str;
        }
        return $str;
    }

    /**
     * Get the frontend module classname from module type and add the "Controller" postfix
     * f.ex. my_custom_module => MyCustomModuleController
     *
     * @param string $postfix
     * @return string
     */
    protected function getSanitizedFrontendModuleClassname(string $postfix = 'Controller'): string
    {
        $str = $this->getSanitizedFrontendModuleType();
        $str = $this->toPsr4Namespace($str);
        return $str . $postfix;
    }

    /**
     * Get model classname f.ex. SampleTableModel
     *
     * @param string $postfix
     * @return string
     * @throws \Exception
     */
    protected function getModelClassname(string $postfix = 'Model'): string
    {
        $str = $this->getSanitizedDcaTableName();
        $str = preg_replace('/^tl_/', '', $str);
        $str = $this->toPsr4Namespace($str);
        return $str . $postfix;
    }

    /**
     * Get the frontend module template name from the frontend module type and add the prefix "mod_"
     *
     * @param string $strPrefix
     * @return string
     */
    protected function getFrontendModuleTemplateName($strPrefix = 'mod_'): string
    {
        $str = $this->getSanitizedFrontendModuleType();
        $str = preg_replace('/_module$/', '', $str);
        return $strPrefix . $str;
    }

    /**
     * Zip folder recursively and store it to a predefined destination
     *
     * @param string $source
     * @param string $destination
     * @return bool
     */
    protected function zipData(string $source, string $destination): bool
    {
        if (extension_loaded('zip'))
        {
            $source = $this->projectDir . '/' . $source;
            $destination = $this->projectDir . '/' . $destination;

            if (file_exists($source))
            {
                $zip = new \ZipArchive();
                if ($zip->open($destination, \ZipArchive::CREATE))
                {
                    $source = realpath($source);
                    if (is_dir($source))
                    {
                        $iterator = new \RecursiveDirectoryIterator($source);

                        // Skip dot files while iterating
                        $iterator->setFlags(\RecursiveDirectoryIterator::SKIP_DOTS);
                        $files = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::SELF_FIRST);
                        foreach ($files as $objSplFileInfo)
                        {
                            $file = $objSplFileInfo->getRealPath();

                            if (is_dir($file))
                            {
                                // Add empty dir and remove the source path
                                $zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
                            }
                            else
                            {
                                if (is_file($file))
                                {
                                    // Add file and remove the source path
                                    $zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
                                }
                            }
                        }
                    }
                    else
                    {
                        if (is_file($source))
                        {
                            $zip->addFromString(basename($source), file_get_contents($source));
                        }
                    }
                }
                return $zip->close();
            }
        }
        return false;
    }

    /**
     * Optionally edit the composer.json file located in the root directory
     *
     * @throws \Exception
     */
    protected function editRootComposerJson(): void
    {
        $blnModified = false;
        $objComposerFile = new File('composer.json');
        $content = $objComposerFile->getContent();
        $objJSON = json_decode($content);

        if ($this->model->rootcomposerextendrepositorieskey !== '')
        {
            if (!isset($objJSON->repositories))
            {
                $objJSON->repositories = [];
            }

            $objRepositories = new \stdClass();

            if ($this->model->rootcomposerextendrequirekey === 'path')
            {
                $objRepositories->type = 'path';
                $objRepositories->url = sprintf('%s/vendor/%s/%s', $this->projectDir, $this->model->vendorname, $this->model->repositoryname);

                // Prevent duplicate entries
                if (!\in_array($objRepositories, $objJSON->repositories))
                {
                    $blnModified = true;
                    $objJSON->repositories[] = $objRepositories;
                    $this->message->addInfo('Extended the repositories section in the root composer.json. Please check!');
                }
            }

            if ($this->model->rootcomposerextendrequirekey === 'vcs-github')
            {
                $objRepositories->type = 'vcs';
                $objRepositories->url = sprintf('https://github.com/%s/%s', $this->model->vendorname, $this->model->repositoryname);

                // Prevent duplicate entries
                if (!\in_array($objRepositories, $objJSON->repositories))
                {
                    $blnModified = true;
                    $objJSON->repositories[] = $objRepositories;
                    $this->message->addInfo('Extended the repositories section in the root composer.json. Please check!');
                }
            }
        }

        if ($this->model->rootcomposerextendrequirekey)
        {
            $blnModified = true;
            $objJSON->require->{sprintf('%s/%s', $this->model->vendorname, $this->model->repositoryname)} = 'dev-master';
            $this->message->addInfo('Extended the require section in the root composer.json. Please check!');
        }

        if ($blnModified)
        {
            // Make a backup first
            $strBackupPath = sprintf('system/tmp/composer_backup_%s.json', Date::parse('Y-m-d _H-i-s', time()));
            Files::getInstance()->copy($objComposerFile->path, $strBackupPath);
            $this->message->addInfo(sprintf('Created backup of composer.json in "%s"', $strBackupPath));

            // Append modifications
            $content = json_encode($objJSON, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $objComposerFile->truncate();
            $objComposerFile->append($content);
            $objComposerFile->close();
        }
    }

    /**
     * Replace tokens
     *
     * Usage:
     * {if addcustomroute=="1"}
     *     use Symfony\Component\HttpKernel\KernelInterface;
     * {endif}
     *
     * or (conditional simple token replacing):
     *
     * {if addcustomroute=="1"}
     *     ##mytoken##
     * {endif}
     *
     * or (replace a simple token):
     *    ##myothertoken##
     *
     * @throws \Exception
     */
    protected function replaceTokens(): void
    {
        $arrTags = $this->tagStorage->getAll();
        $arrFiles = $this->fileStorage->getAll();

        foreach ($arrFiles as $arrFile)
        {
            $content = SimpleTokenParser::parseSimpleTokens($arrFile['content'], $arrTags);
            $this->fileStorage->getFile($arrFile['target'])->replaceContent($content);
        }
    }

    /**
     * Write files from the file storage to the filesystem
     *
     * @todo add a contao hook
     * @throws \Exception
     */
    protected function createFilesFromFileStorage(): void
    {
        $arrFiles = $this->fileStorage->getAll();
        $i = 0;

        /**
         * @todo add a contao hook here
         * Manipulate, remove or add files to the storage
         */
        foreach ($arrFiles as $arrFile)
        {
            // Create file
            $objNewFile = new File($arrFile['target']);

            // Overwrite content if file already exists
            $objNewFile->truncate();
            $objNewFile->append($arrFile['content']);
            $objNewFile->close();

            // Display message in the backend
            $this->message->addInfo(sprintf('Created file "%s".', $objNewFile->path));
            $i++;
        }
        // Display message in the backend
        $this->message->addInfo('Added one or more files to the bundle. Please run at least "composer install" or even "composer update", if you have made changes to the root composer.json.');
    }
}