import React from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { colors, spacing } from '../theme';
import { PillButton } from './PillButton';

/** Centered loading spinner. */
export function LoadingView({ label }: { label?: string }) {
  return (
    <View style={styles.center}>
      <ActivityIndicator size="large" color={colors.royal} />
      {label ? <Text style={styles.muted}>{label}</Text> : null}
    </View>
  );
}

/** Error state with an optional retry. */
export function ErrorView({
  message,
  onRetry,
}: {
  message: string;
  onRetry?: () => void;
}) {
  return (
    <View style={styles.center}>
      <Text style={styles.emoji}>⚠️</Text>
      <Text style={styles.errorText}>{message}</Text>
      {onRetry ? (
        <PillButton
          title="Try again"
          variant="secondary"
          onPress={onRetry}
          style={styles.retryBtn}
        />
      ) : null}
    </View>
  );
}

/** Empty list state. */
export function EmptyView({
  title,
  subtitle,
  icon = '📄',
}: {
  title: string;
  subtitle?: string;
  icon?: string;
}) {
  return (
    <View style={styles.center}>
      <Text style={styles.emoji}>{icon}</Text>
      <Text style={styles.emptyTitle}>{title}</Text>
      {subtitle ? <Text style={styles.muted}>{subtitle}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  center: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.xl,
    minHeight: 240,
  },
  emoji: {
    fontSize: 40,
    marginBottom: spacing.md,
  },
  muted: {
    color: colors.textMuted,
    marginTop: spacing.sm,
    textAlign: 'center',
  },
  errorText: {
    color: colors.text,
    fontSize: 15,
    textAlign: 'center',
    lineHeight: 22,
  },
  emptyTitle: {
    color: colors.text,
    fontSize: 17,
    fontWeight: '700',
    textAlign: 'center',
  },
  retryBtn: {
    marginTop: spacing.lg,
    paddingHorizontal: 32,
  },
});
