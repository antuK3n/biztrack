import React from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { colors } from '../theme';

/**
 * Text-logo wordmark: "Biz" in dark text, magnifier-styled "z", "Track" in
 * royal blue — a lightweight stand-in for the prototype's BizTrack mark.
 */
export function Wordmark({ size = 34 }: { size?: number }) {
  return (
    <View style={styles.row} accessibilityRole="header" accessibilityLabel="BizTrack">
      <Text style={[styles.biz, { fontSize: size }]}>Biz</Text>
      <Text style={[styles.track, { fontSize: size }]}>Track</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'baseline' },
  biz: { color: colors.text, fontWeight: '800', letterSpacing: -0.5 },
  track: { color: colors.royal, fontWeight: '800', letterSpacing: -0.5 },
});
