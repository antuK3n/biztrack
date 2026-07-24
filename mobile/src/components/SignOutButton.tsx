import React from 'react';
import { Alert, Pressable, StyleSheet, Text } from 'react-native';
import { useAuth } from '../context/AuthContext';
import { colors } from '../theme';

/** Header sign-out control. */
export function SignOutButton() {
  const { signOut } = useAuth();
  const onPress = () =>
    Alert.alert('Sign out', 'Sign out of BizTrack?', [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Sign out', style: 'destructive', onPress: () => signOut() },
    ]);

  return (
    <Pressable onPress={onPress} hitSlop={8} style={styles.btn}>
      <Text style={styles.text}>Sign out</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  btn: { paddingHorizontal: 4 },
  text: { color: colors.white, fontWeight: '700', fontSize: 14 },
});
