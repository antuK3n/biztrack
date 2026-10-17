/*
 * The part of `utif` (photopea's TIFF decoder) that uploadConversion.ts uses.
 * The package ships no types; @types/utif exists but describes far more than
 * is called here, and a second dependency for four functions is not worth it.
 */
declare module 'utif' {
  /** One image file directory: TIFF tags as `t<number>`, plus what decodeImage adds. */
  interface Ifd {
    [tag: `t${number}`]: number[] | undefined
    /** NewSubfileType, ImageWidth, X/YResolution, ResolutionUnit. */
    t254?: number[]
    t256?: number[]
    t282?: number[]
    t283?: number[]
    t296?: number[]
    width?: number
    height?: number
    data?: Uint8Array
  }

  const UTIF: {
    decode(buffer: ArrayBuffer): Ifd[]
    decodeImage(buffer: ArrayBuffer, ifd: Ifd, ifds?: Ifd[]): void
    toRGBA8(ifd: Ifd): Uint8Array
    encodeImage(rgba: ArrayBuffer | Uint8Array, width: number, height: number, metadata?: Record<string, unknown>): ArrayBuffer
  }

  export default UTIF
}
