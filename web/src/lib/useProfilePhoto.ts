import { useEffect, useState } from 'react'
import { profilePhoto } from './resources'

/**
 * The signed-in user's profile photo as a blob: URL, or null when they have
 * none and the glyph should be drawn instead.
 *
 * Two screens show the avatar — Settings, which also uploads it, and Profile —
 * and the awkward part is not the fetch but the cleanup. A blob: URL is held by
 * the tab until it is revoked, so a screen that re-fetched on every save and
 * forgot to revoke would keep every previous image alive for as long as the tab
 * lived. Doing it in one place means neither screen has to remember.
 *
 * `version` is what makes a save visible: `hasPhoto` stays true when one photo
 * replaces another, so the effect would not re-run and the old image would stay
 * on screen. Callers that upload bump it; callers that only display leave it.
 */
export function useProfilePhoto(hasPhoto: boolean, version = 0): string | null {
  const [url, setUrl] = useState<string | null>(null)

  useEffect(() => {
    if (!hasPhoto) {
      setUrl(null)
      return
    }

    // `cancelled` covers the unmount-before-resolve race: without it the fetch
    // completes into a dead component and the URL it created is never revoked.
    let cancelled = false
    let created: string | null = null

    profilePhoto
      .objectUrl()
      .then((next) => {
        if (cancelled) {
          if (next) URL.revokeObjectURL(next)
          return
        }
        created = next
        setUrl(next)
      })
      .catch(() => {
        // A photo that cannot be fetched is not worth an error banner over an
        // avatar — fall back to the glyph, which is what no photo looks like.
        if (!cancelled) setUrl(null)
      })

    return () => {
      cancelled = true
      if (created) URL.revokeObjectURL(created)
    }
  }, [hasPhoto, version])

  return url
}
