<?php

namespace Gedmo\Sluggable\Handler;

use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Gedmo\Sluggable\SluggableListener;
use Gedmo\Sluggable\Mapping\Event\SluggableAdapter;
use Gedmo\Tool\Wrapper\AbstractWrapper;
use Gedmo\Exception\InvalidMappingException;

/**
 * Sluggable handler which slugs all parent nodes
 * recursively and synchronizes on updates. For instance
 * category tree slug could look like "food/fruits/apples"
 *
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @package Gedmo.Sluggable.Handler
 * @subpackage TreeSlugHandler
 * @link http://www.gediminasm.org
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class TreeSlugHandler implements SlugHandlerInterface
{
    /**
     * @var Doctrine\Common\Persistence\ObjectManager
     */
    protected $om;

    /**
     * @var Gedmo\Sluggable\SluggableListener
     */
    protected $sluggable;

    /**
     * Options for relative slug handler object
     * classes
     *
     * @var array
     */
    private $options;

    /**
     * Callable of original transliterator
     * which is used by sluggable
     *
     * @var callable
     */
    private $originalTransliterator;

    /**
     * True if node is being inserted
     *
     * @var boolean
     */
    private $isInsert = false;

    /**
     * Transliterated parent slug
     *
     * @var string
     */
    private $parentSlug;

    /**
     * {@inheritDoc}
     */
    public function __construct(SluggableListener $sluggable)
    {
        $this->sluggable = $sluggable;
    }

    /**
     * $options = array(
     *     'separator' => '/',
     *     'parentRelationField' => 'parent'
     * )
     * {@inheritDoc}
     */
    public function getOptions($object)
    {
        $meta = $this->om->getClassMetadata(get_class($object));
        if (!isset($this->options[$meta->name])) {
            $config = $this->sluggable->getConfiguration($this->om, $meta->name);
            $options = $config['handlers'][get_called_class()];
            $default = array(
                'separator' => '/'
            );
            $this->options[$meta->name] = array_merge($default, $options);
        }
        return $this->options[$meta->name];
    }

    /**
     * {@inheritDoc}
     */
    public function onChangeDecision(SluggableAdapter $ea, $slugField, $object, &$slug, &$needToChangeSlug)
    {
        $this->om = $ea->getObjectManager();
        $this->isInsert = $this->om->getUnitOfWork()->isScheduledForInsert($object);
        if (!$this->isInsert && !$needToChangeSlug) {
            $changeSet = $ea->getObjectChangeSet($this->om->getUnitOfWork(), $object);
            $options = $this->getOptions($object);
            if (isset($changeSet[$options['parentRelationField']])) {
                $needToChangeSlug = true;
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function postSlugBuild(SluggableAdapter $ea, $slugField, $object, &$slug)
    {
        $options = $this->getOptions($object);
        $this->originalTransliterator = $this->sluggable->getTransliterator();
        $this->sluggable->setTransliterator(array($this, 'transliterate'));
        $this->parentSlug = '';

        $wrapped = AbstractWrapper::wrap($object, $this->om);
        if ($parent = $wrapped->getPropertyValue($options['parentRelationField'])) {
            $parent = AbstractWrapper::wrap($parent, $this->om);
            $this->parentSlug = $parent->getPropertyValue($slugField);
        }
    }

    /**
     * {@inheritDoc}
     */
    public static function validate(array $options, ClassMetadata $meta)
    {
        if (!$meta->isSingleValuedAssociation($options['parentRelationField'])) {
            throw new InvalidMappingException("Unable to find tree parent slug relation through field - [{$options['parentRelationField']}] in class - {$meta->name}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function onSlugCompletion(SluggableAdapter $ea, $slugField, $object, &$slug)
    {
        if (!$this->isInsert) {
            $options = $this->getOptions($object);
            $wrapped = AbstractWrapper::wrap($object, $this->om);
            $meta = $wrapped->getMetadata();
            $target = $wrapped->getPropertyValue($slugField);

            $uow = $this->om->getUnitOfWork();
            // update in memory objects
            foreach ($uow->getIdentityMap() as $className => $objects) {
                // for inheritance mapped classes, only root is always in the identity map
                if ($className !== $wrapped->getRootObjectName()) {
                    continue;
                }
                foreach ($objects as $object) {
                    if (property_exists($object, '__isInitialized__') && !$object->__isInitialized__) {
                        continue;
                    }
                    $oid = spl_object_hash($object);
                    $objectSlug = $meta->getReflectionProperty($slugField)->getValue($object);
                    if (preg_match("@^{$target}{$options['separator']}@smi", $objectSlug)) {
                        $objectSlug = str_replace($target, $slug, $objectSlug);
                        $meta->getReflectionProperty($slugField)->setValue($object, $objectSlug);
                        $ea->setOriginalObjectProperty($uow, $oid, $slugField, $objectSlug);
                    }
                }
            }
        }
    }

    /**
     * Transliterates the slug and prefixes the slug
     * by collection of parent slugs
     *
     * @param string $text
     * @param string $separator
     * @param object $object
     * @return string
     */
    public function transliterate($text, $separator, $object)
    {
        $slug = call_user_func_array(
            $this->originalTransliterator,
            array($text, $separator, $object)
        );
        if (strlen($this->parentSlug)) {
            $options = $this->getOptions($object);
            $slug = $this->parentSlug . $options['separator'] . $slug;
        }
        $this->sluggable->setTransliterator($this->originalTransliterator);
        return $slug;
    }
}