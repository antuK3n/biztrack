/*
 * The team's BizTrack logo (public/logo.png, 900×141 transparent PNG).
 * Full-color: blue "Biz" + black "Track" — needs a light background; on
 * imagery, set it on a light plate (see AuthLayout).
 */
export function Logo({ height = 28, alt = 'BizTrack' }: { height?: number; alt?: string }) {
  return (
    <img
      src="/logo.png"
      alt={alt}
      height={height}
      width={Math.round(height * (900 / 141))}
      style={{ height, width: 'auto' }}
    />
  )
}
