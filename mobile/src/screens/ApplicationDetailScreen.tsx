import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import React, { useCallback, useEffect, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { api, apiError } from '../api';
import { Card } from '../components/Card';
import { PillButton } from '../components/PillButton';
import { ErrorView, LoadingView } from '../components/StateViews';
import { peso, shortDate } from '../format';
import type { TrackStackParamList } from '../navigation';
import { colors, humanize, radius, spacing, statusStyle } from '../theme';
import type { ApplicationDetail } from '../types';

type Props = NativeStackScreenProps<TrackStackParamList, 'ApplicationDetail'>;

// Statuses that block a payment CTA / drive the messaging in the status card.
function isPendingPayment(status: string) {
  return ['pending_payment', 'for_payment', 'payment_pending'].includes(
    (status || '').toLowerCase(),
  );
}
function isReturnedOrRejected(status: string) {
  return ['returned', 'rejected'].includes((status || '').toLowerCase());
}

export function ApplicationDetailScreen({ route, navigation }: Props) {
  const { id } = route.params;
  const [app, setApp] = useState<ApplicationDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [acting, setActing] = useState(false);

  const load = useCallback(async () => {
    setError(null);
    try {
      const res = await api.get<{ data: ApplicationDetail }>(`/applications/${id}`);
      setApp(res.data.data);
      navigation.setOptions({ title: res.data.data.tracking_id });
    } catch (e) {
      setError(apiError(e));
    } finally {
      setLoading(false);
    }
  }, [id, navigation]);

  useEffect(() => {
    load();
  }, [load]);

  const onPay = () => {
    Alert.alert('Pay online', 'Simulate a GCash payment for this assessment?', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Pay with GCash',
        onPress: async () => {
          setActing(true);
          try {
            await api.post(`/applications/${id}/pay`, { method: 'gcash' });
            await load();
            Alert.alert('Payment complete', 'Your simulated payment was recorded.');
          } catch (e) {
            Alert.alert('Payment failed', apiError(e));
          } finally {
            setActing(false);
          }
        },
      },
    ]);
  };

  const onResubmit = () => {
    Alert.alert('Resubmit', 'Resubmit this application for review?', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Resubmit',
        onPress: async () => {
          setActing(true);
          try {
            await api.post(`/applications/${id}/resubmit`);
            await load();
            Alert.alert('Resubmitted', 'Your application is back under review.');
          } catch (e) {
            Alert.alert('Could not resubmit', apiError(e));
          } finally {
            setActing(false);
          }
        },
      },
    ]);
  };

  if (loading) return <LoadingView label="Loading application…" />;
  if (error || !app) return <ErrorView message={error ?? 'Not found'} onRetry={load} />;

  const s = statusStyle(app.status);
  const fee = app.fee_assessment;
  const returned = isReturnedOrRejected(app.status);
  const pendingPay = isPendingPayment(app.status);

  return (
    <SafeAreaView style={styles.safe} edges={['bottom']}>
      <ScrollView contentContainerStyle={styles.scroll}>
        <Text style={styles.appType}>{humanize(app.application_type)}</Text>
        <Text style={styles.business}>{app.business?.name}</Text>

        {/* Colored status-bar card, mirroring the web status card. */}
        <View style={styles.statusCard}>
          <View style={[styles.statusBar, { backgroundColor: s.color }]} />
          <View style={styles.statusBody}>
            <View style={styles.statusRow}>
              <View style={[styles.statusDot, { backgroundColor: s.color }]} />
              <Text style={styles.statusLabel}>{app.status_label || s.label}</Text>
            </View>

            {app.deadline_at && (pendingPay || s.color === colors.orange) ? (
              <Text style={styles.deadline}>
                Action by {shortDate(app.deadline_at)}
              </Text>
            ) : null}

            {returned && app.rejection_reason ? (
              <View style={styles.remarksBox}>
                <Text style={styles.remarksTitle}>Officer remarks</Text>
                <Text style={styles.remarksText}>{app.rejection_reason}</Text>
              </View>
            ) : null}

            {returned ? (
              <PillButton
                title="Resubmit"
                onPress={onResubmit}
                loading={acting}
                style={styles.actionBtn}
              />
            ) : null}
          </View>
        </View>

        {/* Fee summary */}
        {fee ? (
          <Card style={styles.section}>
            <Text style={styles.sectionTitle}>Fee summary</Text>
            {(fee.line_items ?? []).map((li, i) => (
              <View key={i} style={styles.feeRow}>
                <Text style={styles.feeLabel}>{li.label}</Text>
                <Text style={styles.feeAmount}>{peso(li.amount)}</Text>
              </View>
            ))}
            <View style={[styles.feeRow, styles.feeTotalRow]}>
              <Text style={styles.feeTotalLabel}>Total</Text>
              <Text style={styles.feeTotalAmount}>{peso(fee.total_amount)}</Text>
            </View>

            {pendingPay ? (
              <PillButton
                title="Pay online (GCash)"
                onPress={onPay}
                loading={acting}
                style={styles.actionBtn}
              />
            ) : null}
          </Card>
        ) : null}

        {/* Payment history */}
        {app.payments && app.payments.length > 0 ? (
          <Card style={styles.section}>
            <Text style={styles.sectionTitle}>Payments</Text>
            {app.payments.map((p) => (
              <View key={p.id} style={styles.feeRow}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.feeLabel}>{p.reference_number}</Text>
                  <Text style={styles.paySub}>
                    {(p.method || '').toUpperCase()} · {humanize(p.status)}
                  </Text>
                </View>
                <Text style={styles.feeAmount}>{peso(p.amount)}</Text>
              </View>
            ))}
          </Card>
        ) : null}
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.canvas },
  scroll: { padding: spacing.xl },
  appType: { color: colors.textMuted, fontSize: 14, fontWeight: '700' },
  business: {
    color: colors.text,
    fontSize: 22,
    fontWeight: '800',
    marginTop: 2,
    marginBottom: spacing.lg,
  },
  statusCard: {
    backgroundColor: colors.white,
    borderRadius: radius.card,
    overflow: 'hidden',
    shadowColor: '#1a2233',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.1,
    shadowRadius: 12,
    elevation: 3,
  },
  statusBar: { height: 10, width: '100%' },
  statusBody: { padding: spacing.lg },
  statusRow: { flexDirection: 'row', alignItems: 'center' },
  statusDot: { width: 12, height: 12, borderRadius: 999, marginRight: spacing.sm },
  statusLabel: { fontSize: 20, fontWeight: '800', color: colors.text },
  deadline: { color: colors.textMuted, marginTop: spacing.sm, fontSize: 14 },
  remarksBox: {
    marginTop: spacing.md,
    backgroundColor: '#fdecec',
    borderRadius: radius.input,
    padding: spacing.md,
  },
  remarksTitle: {
    color: colors.red,
    fontWeight: '700',
    fontSize: 13,
    marginBottom: spacing.xs,
  },
  remarksText: { color: colors.text, fontSize: 14, lineHeight: 20 },
  actionBtn: { marginTop: spacing.lg },
  section: { marginTop: spacing.lg },
  sectionTitle: {
    fontSize: 16,
    fontWeight: '800',
    color: colors.text,
    marginBottom: spacing.md,
  },
  feeRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 6,
  },
  feeLabel: { color: colors.text, fontSize: 14, flex: 1, marginRight: spacing.md },
  feeAmount: { color: colors.text, fontSize: 14, fontWeight: '700' },
  paySub: { color: colors.textMuted, fontSize: 12, marginTop: 2 },
  feeTotalRow: {
    borderTopWidth: 1,
    borderTopColor: colors.border,
    marginTop: spacing.sm,
    paddingTop: spacing.md,
  },
  feeTotalLabel: { color: colors.text, fontSize: 16, fontWeight: '800' },
  feeTotalAmount: { color: colors.royal, fontSize: 18, fontWeight: '800' },
});
