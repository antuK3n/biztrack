import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { colors, radius, statusStyle } from '../theme';

/**
 * Status is always communicated by a colored dot + text (never color alone),
 * per the WCAG-minded design system.
 */
export function StatusChip({ status, label }: { status: string; label?: string }) {
  const s = statusStyle(status);
  return (
    <View style={[styles.chip, { backgroundColor: s.color }]}>
      <View style={styles.dot} />
      <Text style={styles.text} numberOfLines={1}>
        {label || s.label}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    borderRadius: radius.pill,
    paddingVertical: 5,
    paddingHorizontal: 10,
    alignSelf: 'flex-start',
    maxWidth: 160,
  },
  dot: {
    width: 7,
    height: 7,
    borderRadius: 999,
    backgroundColor: colors.white,
    marginRight: 6,
    opacity: 0.9,
  },
  text: {
    color: colors.white,
    fontWeight: '700',
    fontSize: 12,
  },
});
