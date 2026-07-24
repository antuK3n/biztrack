import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import React, { useCallback, useEffect, useState } from 'react';
import { Linking, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { api, apiError } from '../api';
import { Card } from '../components/Card';
import { PillButton } from '../components/PillButton';
import { ErrorView, LoadingView } from '../components/StateViews';
import { StatusChip } from '../components/StatusChip';
import { shortDate } from '../format';
import type { PermitsStackParamList } from '../navigation';
import { colors, radius, spacing } from '../theme';
import type { Permit } from '../types';

type Props = NativeStackScreenProps<PermitsStackParamList, 'PermitDetail'>;

export function PermitDetailScreen({ route, navigation }: Props) {
  const { id } = route.params;
  const [permit, setPermit] = useState<Permit | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const res = await api.get<{ data: Permit }>(`/permits/${id}`);
      setPermit(res.data.data);
      navigation.setOptions({ title: res.data.data.permit_number });
    } catch (e) {
      setError(apiError(e));
    } finally {
      setLoading(false);
    }
  }, [id, navigation]);

  useEffect(() => {
    load();
  }, [load]);

  if (loading) return <LoadingView label="Loading permit…" />;
  if (error || !permit) return <ErrorView message={error ?? 'Not found'} onRetry={load} />;

  const expiry = permit.days_until_expiry;

  return (
    <SafeAreaView style={styles.safe} edges={['bottom']}>
      <ScrollView contentContainerStyle={styles.scroll}>
        <Card>
          <View style={styles.head}>
            <View style={{ flex: 1 }}>
              <Text style={styles.number}>{permit.permit_number}</Text>
              <Text style={styles.type}>{permit.permit_type?.name}</Text>
            </View>
            <StatusChip status={permit.status} label={permit.status_label} />
          </View>

          <View style={styles.divider} />

          <Row label="Business" value={permit.business?.name} />
          <Row label="Valid from" value={shortDate(permit.valid_from)} />
          <Row label="Valid until" value={shortDate(permit.valid_until)} />
          {typeof expiry === 'number' ? (
            <Row
              label="Expiry"
              value={
                expiry < 0
                  ? `Expired ${Math.abs(expiry)} day(s) ago`
                  : `${expiry} day(s) remaining`
              }
            />
          ) : null}
        </Card>

        {/* Verification link, prominently surfaced. */}
        <Card style={styles.verifyCard}>
          <Text style={styles.verifyTitle}>Public verification</Text>
          <Text style={styles.verifyDesc}>
            Anyone can confirm this permit is authentic at the address below.
          </Text>
          <View style={styles.urlBox}>
            <Text style={styles.url} selectable numberOfLines={2}>
              {permit.verify_url}
            </Text>
          </View>
          <PillButton
            title="Open verification page"
            onPress={() => Linking.openURL(permit.verify_url)}
            style={styles.verifyBtn}
          />
        </Card>
      </ScrollView>
    </SafeAreaView>
  );
}

function Row({ label, value }: { label: string; value?: string | null }) {
  return (
    <View style={styles.row}>
      <Text style={styles.rowLabel}>{label}</Text>
      <Text style={styles.rowValue}>{value ?? '—'}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.canvas },
  scroll: { padding: spacing.xl },
  head: { flexDirection: 'row', alignItems: 'flex-start' },
  number: { fontSize: 20, fontWeight: '800', color: colors.text },
  type: { fontSize: 14, color: colors.textMuted, marginTop: 2 },
  divider: {
    height: 1,
    backgroundColor: colors.border,
    marginVertical: spacing.md,
  },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 6,
  },
  rowLabel: { color: colors.textMuted, fontSize: 14 },
  rowValue: {
    color: colors.text,
    fontSize: 14,
    fontWeight: '700',
    flexShrink: 1,
    textAlign: 'right',
    marginLeft: spacing.md,
  },
  verifyCard: { marginTop: spacing.lg },
  verifyTitle: { fontSize: 16, fontWeight: '800', color: colors.text },
  verifyDesc: {
    color: colors.textMuted,
    fontSize: 13,
    marginTop: spacing.xs,
    lineHeight: 19,
  },
  urlBox: {
    backgroundColor: colors.input,
    borderRadius: radius.input,
    padding: spacing.md,
    marginTop: spacing.md,
  },
  url: { color: colors.royalDark, fontSize: 13 },
  verifyBtn: { marginTop: spacing.md },
});
