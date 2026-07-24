import React, { useState } from 'react';
import {
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { apiError } from '../api';
import { PillButton } from '../components/PillButton';
import { Wordmark } from '../components/Wordmark';
import { useAuth } from '../context/AuthContext';
import { colors, radius, spacing } from '../theme';

export function LoginScreen() {
  const { signIn } = useAuth();
  const [identifier, setIdentifier] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const onSubmit = async () => {
    if (!identifier.trim() || !password) {
      setError('Please enter your email/number and password.');
      return;
    }
    setError(null);
    setLoading(true);
    try {
      await signIn(identifier.trim(), password);
    } catch (e) {
      setError(apiError(e));
    } finally {
      setLoading(false);
    }
  };

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'bottom']}>
      <KeyboardAvoidingView
        style={styles.flex}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        <ScrollView
          contentContainerStyle={styles.scroll}
          keyboardShouldPersistTaps="handled"
        >
          <View style={styles.logoWrap}>
            <Wordmark size={44} />
            <Text style={styles.tagline}>Business permit tracking</Text>
          </View>

          <View style={styles.card}>
            <Text style={styles.label}>Email or number</Text>
            <TextInput
              style={styles.input}
              placeholder="Email or number"
              placeholderTextColor={colors.textMuted}
              autoCapitalize="none"
              autoCorrect={false}
              keyboardType="email-address"
              value={identifier}
              onChangeText={setIdentifier}
              editable={!loading}
            />

            <Text style={styles.label}>Password</Text>
            <TextInput
              style={styles.input}
              placeholder="Password"
              placeholderTextColor={colors.textMuted}
              secureTextEntry
              value={password}
              onChangeText={setPassword}
              editable={!loading}
              onSubmitEditing={onSubmit}
              returnKeyType="go"
            />

            {error ? <Text style={styles.error}>{error}</Text> : null}

            <PillButton
              title="Sign In"
              onPress={onSubmit}
              loading={loading}
              style={styles.signIn}
            />

            <View style={styles.demoBox}>
              <Text style={styles.demoTitle}>Demo owner account</Text>
              <Text style={styles.demoText}>owner@biztrack.local</Text>
              <Text style={styles.demoText}>biztrack1</Text>
            </View>
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.canvas },
  flex: { flex: 1 },
  scroll: {
    flexGrow: 1,
    justifyContent: 'center',
    padding: spacing.xl,
  },
  logoWrap: { alignItems: 'center', marginBottom: spacing.xl },
  tagline: { color: colors.textMuted, marginTop: spacing.sm, fontSize: 14 },
  card: {
    backgroundColor: colors.white,
    borderRadius: radius.card,
    padding: spacing.xl,
    shadowColor: '#1a2233',
    shadowOffset: { width: 0, height: 4 },
    shadowOpacity: 0.1,
    shadowRadius: 12,
    elevation: 3,
  },
  label: {
    color: colors.text,
    fontWeight: '700',
    fontSize: 14,
    marginBottom: spacing.sm,
  },
  input: {
    backgroundColor: colors.input,
    borderRadius: radius.input,
    paddingVertical: 14,
    paddingHorizontal: 16,
    fontSize: 16,
    color: colors.text,
    marginBottom: spacing.lg,
  },
  error: {
    color: colors.red,
    marginBottom: spacing.md,
    fontSize: 14,
  },
  signIn: { marginTop: spacing.xs },
  demoBox: {
    marginTop: spacing.xl,
    backgroundColor: colors.canvas,
    borderRadius: radius.input,
    padding: spacing.md,
    alignItems: 'center',
  },
  demoTitle: {
    color: colors.textMuted,
    fontSize: 12,
    fontWeight: '700',
    textTransform: 'uppercase',
    letterSpacing: 0.5,
    marginBottom: spacing.xs,
  },
  demoText: { color: colors.text, fontSize: 14 },
});
