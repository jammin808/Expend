/**
 * A receipt photo, made ready on the phone before it's kept: turned the right way up, its longest side at most
 * 2,000 px, saved as a JPEG at 0.82 (typically 250-600 KB), with a 200 px thumbnail for the lists. A phone camera's
 * 50-200 MP photo can be too big to decode, and Android Chrome can't decode HEIC: then the original bytes are kept and
 * sent as they are (original: true). Saving is never blocked. PDFs are kept as they are.
 */

export const MAX_SIDE = 2000;
export const THUMB_SIDE = 200;

/** A size as the server says it (QuickController::megabytes): one decimal place, "1.9 MB", "30 MB". */
export function megabytes(bytes: number): string {
    const mb = Math.max(0.1, Math.round((bytes / 1048576) * 10) / 10);
    return `${Number(mb.toFixed(1))} MB`;
}
export const JPEG_QUALITY = 0.82;
/** PDFs over this (or the server's own limit, if smaller) are refused before they're queued. */
export const PDF_LIMIT = 10 * 1024 * 1024;

export interface PreparedFile {
    bytes: ArrayBuffer;
    type: string;
    size: number;
    sha256: string;
    name: string;
    /** True when the phone couldn't re-encode it (sent as it was). */
    original: boolean;
    /** A small JPEG data URL for the lists, or null (a PDF, or nothing could be drawn). */
    thumb: string | null;
    pdf: boolean;
}

export async function sha256Hex(bytes: ArrayBuffer): Promise<string> {
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(digest))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');
}

export function isPdf(file: { type?: string; name?: string }): boolean {
    return file.type === 'application/pdf' || /\.pdf$/i.test(file.name ?? '');
}

/** 'receipt-20261125-1405.jpg', in London time. */
export function receiptName(at: Date, ext: string): string {
    const parts: Record<string, string> = {};
    new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Europe/London',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    })
        .formatToParts(at)
        .forEach((p) => {
            parts[p.type] = p.value;
        });
    return `receipt-${parts.year}${parts.month}${parts.day}-${parts.hour}${parts.minute}.${ext}`;
}

function extFor(type: string, name: string): string {
    if (type === 'image/jpeg') return 'jpg';
    if (type === 'application/pdf') return 'pdf';
    const fromType = type.split('/')[1];
    if (fromType && /^[a-z0-9.+-]{2,8}$/.test(fromType)) return fromType.replace('+xml', '');
    const fromName = /\.([a-z0-9]{2,5})$/i.exec(name);
    return fromName ? fromName[1].toLowerCase() : 'bin';
}

type Drawable = { source: CanvasImageSource; width: number; height: number; close: () => void };

async function decodeBitmap(blob: Blob): Promise<Drawable | null> {
    if (typeof createImageBitmap !== 'function') return null;
    try {
        const bitmap = await createImageBitmap(blob, { imageOrientation: 'from-image' });
        return { source: bitmap, width: bitmap.width, height: bitmap.height, close: () => bitmap.close() };
    } catch {
        return null;
    }
}

/** An <img> decode also applies the photo's EXIF orientation, and copes where createImageBitmap runs out of memory. */
async function decodeImg(blob: Blob): Promise<Drawable | null> {
    const url = URL.createObjectURL(blob);
    try {
        const img = new Image();
        img.decoding = 'async';
        img.src = url;
        await img.decode();
        if (!img.naturalWidth || !img.naturalHeight) return null;
        return { source: img, width: img.naturalWidth, height: img.naturalHeight, close: () => URL.revokeObjectURL(url) };
    } catch {
        URL.revokeObjectURL(url);
        return null;
    }
}

function fit(width: number, height: number, max: number): [number, number] {
    const scale = Math.min(1, max / Math.max(width, height));
    return [Math.max(1, Math.round(width * scale)), Math.max(1, Math.round(height * scale))];
}

function draw(image: Drawable, max: number): HTMLCanvasElement | null {
    const [w, h] = fit(image.width, image.height, max);
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    if (!ctx) return null;
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, w, h);
    ctx.drawImage(image.source, 0, 0, w, h);
    return canvas;
}

function toJpeg(canvas: HTMLCanvasElement, quality: number): Promise<Blob | null> {
    return new Promise((resolve) => {
        try {
            canvas.toBlob((blob) => resolve(blob), 'image/jpeg', quality);
        } catch {
            resolve(null);
        }
    });
}

/** A small thumbnail data URL from any image blob (also used for shared photos and the kept original). */
export async function thumbnailOf(blob: Blob): Promise<string | null> {
    const image = (await decodeBitmap(blob)) ?? (await decodeImg(blob));
    if (!image) return null;
    try {
        const canvas = draw(image, THUMB_SIDE);
        return canvas ? canvas.toDataURL('image/jpeg', 0.7) : null;
    } catch {
        return null;
    } finally {
        image.close();
    }
}

/**
 * Make a picked or shared file ready to keep. at is when it was taken (for the name). Throws only for a PDF over the
 * limit, with the words to show.
 */
export async function prepareFile(file: Blob & { name?: string }, at: Date, pdfLimit: number = PDF_LIMIT): Promise<PreparedFile> {
    const name = file.name ?? '';
    if (isPdf({ type: file.type, name })) {
        if (file.size > pdfLimit) {
            throw new Error(`Too big to send from your phone (over ${megabytes(pdfLimit)}). Upload it from a computer.`);
        }
        const bytes = await file.arrayBuffer();
        return {
            bytes,
            type: 'application/pdf',
            size: bytes.byteLength,
            sha256: await sha256Hex(bytes),
            name: receiptName(at, 'pdf'),
            original: true,
            thumb: null,
            pdf: true,
        };
    }

    const image = (await decodeBitmap(file)) ?? (await decodeImg(file));
    if (image) {
        try {
            const canvas = draw(image, MAX_SIDE);
            const jpeg = canvas ? await toJpeg(canvas, JPEG_QUALITY) : null;
            const small = draw(image, THUMB_SIDE);
            const thumb = small ? small.toDataURL('image/jpeg', 0.7) : null;
            if (jpeg && jpeg.size > 0) {
                const bytes = await jpeg.arrayBuffer();
                return {
                    bytes,
                    type: 'image/jpeg',
                    size: bytes.byteLength,
                    sha256: await sha256Hex(bytes),
                    name: receiptName(at, 'jpg'),
                    original: false,
                    thumb,
                    pdf: false,
                };
            }
        } catch {
            // Fall through to the original bytes.
        } finally {
            image.close();
        }
    }

    // Couldn't decode or draw it (HEIC on Android, say): keep the original and send it as it is.
    const bytes = await file.arrayBuffer();
    const type = file.type || 'application/octet-stream';
    return {
        bytes,
        type,
        size: bytes.byteLength,
        sha256: await sha256Hex(bytes),
        name: receiptName(at, extFor(type, name)),
        original: true,
        thumb: null,
        pdf: false,
    };
}
