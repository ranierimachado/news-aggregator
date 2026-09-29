<?php

declare(strict_types=1);

namespace Drupal\maemgaba_core;

use Drupal\node\NodeInterface;

/**
 * What a card counts as on an event: a perspective, a reprint, or opinion.
 *
 * Only perspectives (original news reporting) go into an event's bucket
 * distribution, spectrum bar, "N perspectives" count and consensus input.
 * Reprints (field_syndicated_from set) are listed under the card they copy;
 * opinion pieces (field_section = opinion) are classified but shown in their
 * own strip. On a site without those fields or values every card is a
 * perspective, as before.
 */
final class CardRole {

  /**
   * The field_section value of opinion pieces.
   */
  public const SECTION_OPINION = 'opinion';

  /**
   * Whether the card reprints another card.
   */
  public static function isSyndicated(NodeInterface $card): bool {
    return $card->hasField('field_syndicated_from') && !$card->get('field_syndicated_from')->isEmpty();
  }

  /**
   * Whether the card is an opinion piece.
   */
  public static function isOpinion(NodeInterface $card): bool {
    return $card->hasField('field_section') && $card->get('field_section')->value === self::SECTION_OPINION;
  }

  /**
   * Whether the card counts as one of the event's perspectives.
   */
  public static function isPerspective(NodeInterface $card): bool {
    return !self::isSyndicated($card) && !self::isOpinion($card);
  }

}
