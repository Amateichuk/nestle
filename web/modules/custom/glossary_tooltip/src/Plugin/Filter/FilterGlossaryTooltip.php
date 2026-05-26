<?php

declare(strict_types=1);

namespace Drupal\glossary_tooltip\Plugin\Filter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\glossary_tooltip\Service\GlossaryParser;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Wraps glossary term matches with tooltip markup.
 *
 * @Filter(
 *   id = "filter_glossary_tooltip",
 *   title = @Translation("Glossary tooltip"),
 *   description = @Translation("Highlights words that match a glossary vocabulary term and shows the term description in a tooltip."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_REVERSIBLE,
 *   weight = 50
 * )
 *
 * Implementation notes:
 *
 *  - The filter runs inside the standard text format pipeline, so its
 *    output is cached by Drupal per (text, format, langcode). This is
 *    the recommended place for expensive text post-processing.
 *  - The "taxonomy_term_list:glossary" cache tag is attached to the
 *    result. When any glossary term is created, updated or deleted
 *    (see glossary_tooltip.module), this tag is invalidated and
 *    rendered content is regenerated on demand.
 *  - Heavy lifting is delegated to GlossaryParser so the same logic
 *    can be reused outside the filter (drush, tests, etc.).
 */
final class FilterGlossaryTooltip extends FilterBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly GlossaryParser $parser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('glossary_tooltip.parser'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode): FilterProcessResult {
    $processed = $this->parser->process((string) $text);

    $result = new FilterProcessResult($processed);
    // Invalidate this filter's output when the vocabulary changes.
    $result->addCacheTags([GlossaryParser::CACHE_TAG]);
    // Front-end assets needed to actually render the tooltip.
    $result->setAttachments([
      'library' => ['glossary_tooltip/tooltip'],
    ]);
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    return $this->t('Words matching a glossary term will be highlighted with their description in a tooltip.');
  }

}
