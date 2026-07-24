// BizTrack visual system — royal blue civic palette.
export const colors = {
  royal: '#3242ca',
  royalDark: '#25309a',
  canvas: '#d1dbeb',
  white: '#ffffff',
  input: '#cfe0f7',
  text: '#1a2233',
  textMuted: '#5b6577',
  border: '#e3e9f4',

  // Status colors — always paired with an icon/dot + text.
  orange: '#f2a33c',
  green: '#22b573',
  yellow: '#f5c518',
  red: '#c11212',
};

export const radius = {
  card: 16,
  pill: 999,
  input: 12,
};

export const spacing = {
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
};

export const shadow = {
  // Soft card shadow, cross-platform.
  shadowColor: '#1a2233',
  shadowOffset: { width: 0, height: 4 },
  shadowOpacity: 0.1,
  shadowRadius: 12,
  elevation: 3,
};

/**
 * Maps a backend status string to a display color + dot. Statuses come from
 * WorkflowService (draft, under_review, for_approval, pending_payment,
 * for_inspection, approved, rejected, returned, cancelled, expired...).
 */
export function statusStyle(status: string): { color: string; label: string } {
  const s = (status || '').toLowerCase();
  if (['approved', 'paid', 'active', 'valid', 'issued', 'fulfilled', 'submitted'].includes(s)) {
    return { color: colors.green, label: humanize(status) };
  }
  if (['for_inspection', 'inspection', 'scheduled', 'conditional'].includes(s)) {
    return { color: colors.yellow, label: humanize(status) };
  }
  if (
    ['rejected', 'returned', 'cancelled', 'expired', 'failed', 'suspended', 'blacklisted', 'revoked'].includes(
      s,
    )
  ) {
    return { color: colors.red, label: humanize(status) };
  }
  // pending / under_review / for_approval / pending_payment / draft
  return { color: colors.orange, label: humanize(status) };
}

export function humanize(status: string): string {
  if (!status) return '';
  return status
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}
