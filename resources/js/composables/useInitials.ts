/**
 * A composable for safely generating user initials from names
 */
export function useInitials() {
  /**
   * Generate initials from a name with safety checks
   * 
   * @param name The user's name or null/undefined
   * @param fallback Fallback character if name is invalid
   * @returns Uppercase initials
   */
  const getInitials = (name: string | null | undefined, fallback: string = '?'): string => {
    if (!name) return fallback;

    const names = name.trim().split(/\s+/);
    if (names.length === 0 || names[0] === '') return fallback;

    if (names.length === 1) return names[0].charAt(0).toUpperCase();

    // For multiple names, get first and last initials
    return (names[0].charAt(0) + names[names.length - 1].charAt(0)).toUpperCase();
  };

  return {
    getInitials
  };
}
