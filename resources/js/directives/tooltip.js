/**
 * Simple tooltip directive for Vue 3
 */
export default {
  mounted(el, binding) {
    if (!binding.value) return;

    // Create tooltip element
    const tooltip = document.createElement('div');
    tooltip.innerHTML = binding.value;
    tooltip.className = 'tooltip hidden absolute z-50 bg-gray-900 text-white text-xs rounded py-1 px-2 max-w-xs';
    document.body.appendChild(tooltip);

    // Show tooltip on hover
    el.addEventListener('mouseenter', () => {
      const rect = el.getBoundingClientRect();
      tooltip.classList.remove('hidden');
      tooltip.style.left = `${rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2)}px`;
      tooltip.style.top = `${rect.top - tooltip.offsetHeight - 5}px`;
    });

    // Hide tooltip
    el.addEventListener('mouseleave', () => {
      tooltip.classList.add('hidden');
    });

    // Store tooltip reference
    el._tooltip = tooltip;
  },

  updated(el, binding) {
    if (el._tooltip && binding.value) {
      el._tooltip.innerHTML = binding.value;
    }
  },

  unmounted(el) {
    if (el._tooltip) {
      document.body.removeChild(el._tooltip);
      delete el._tooltip;
    }
  }
};
