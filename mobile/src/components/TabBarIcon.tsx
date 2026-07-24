import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { colors } from '../theme';

// Emoji glyphs per tab — no vector-icon dependency needed for the capstone.
const GLYPHS: Record<string, string> = {
  Home: '🏠',
  Track: '📍',
  Permits: '🎫',
  Alerts: '🔔',
};

export function TabBarIcon({
  name,
  focused,
  badge,
}: {
  name: string;
  focused: boolean;
  badge?: number;
}) {
  return (
    <View style={styles.wrap}>
      <Text style={[styles.glyph, { opacity: focused ? 1 : 0.55 }]}>
        {GLYPHS[name] ?? '•'}
      </Text>
      {badge && badge > 0 ? (
        <View style={styles.badge}>
          <Text style={styles.badgeText}>{badge > 99 ? '99+' : badge}</Text>
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { width: 34, height: 30, alignItems: 'center', justifyContent: 'center' },
  glyph: { fontSize: 22 },
  badge: {
    position: 'absolute',
    top: -4,
    right: -6,
    minWidth: 18,
    height: 18,
    borderRadius: 9,
    backgroundColor: colors.red,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 4,
    borderWidth: 1.5,
    borderColor: colors.royal,
  },
  badgeText: { color: colors.white, fontSize: 10, fontWeight: '800' },
});
