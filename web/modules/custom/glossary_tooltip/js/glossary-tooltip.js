/**
 * @file
 * Builds tooltip bubbles for elements rendered by the
 * glossary_tooltip module.
 *
 * The server only emits a single <span class="glossary-tooltip"> per
 * match with data-description / data-read-more-url. The bubble itself
 * is created here once per element (via core/once) so the HTML stays
 * small in the cached filter output regardless of how many tooltips
 * a long page contains.
 */
(function (Drupal, once) {
  'use strict';

  function buildBubble(el) {
    const description = el.getAttribute('data-description') || '';
    const more = el.getAttribute('data-read-more-url') || '';

    const bubble = document.createElement('span');
    bubble.className = 'glossary-tooltip__bubble';

    const text = document.createElement('span');
    text.className = 'glossary-tooltip__text';
    text.textContent = description;
    bubble.appendChild(text);

    if (more) {
      const link = document.createElement('a');
      link.className = 'glossary-tooltip__more';
      link.href = more;
      link.textContent = Drupal.t('Read more');
      bubble.appendChild(document.createElement('br'));
      bubble.appendChild(link);
    }

    // Make the description accessible to screen readers.
    el.setAttribute('aria-describedby', '');
    el.appendChild(bubble);

    // Reposition to the right edge if the bubble would overflow.
    const reposition = function () {
      bubble.classList.remove('glossary-tooltip__bubble--right');
      const rect = bubble.getBoundingClientRect();
      if (rect.right > window.innerWidth - 8) {
        bubble.classList.add('glossary-tooltip__bubble--right');
      }
    };
    el.addEventListener('mouseenter', reposition);
    el.addEventListener('focus', reposition);
  }

  Drupal.behaviors.glossaryTooltip = {
    attach: function (context) {
      once('glossary-tooltip', '.glossary-tooltip', context).forEach(buildBubble);
    },
  };
})(Drupal, once);
