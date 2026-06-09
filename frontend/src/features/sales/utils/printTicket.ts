export function writeTicketAndPrint(win: Window, html: string): void {
  resizePrintWindow(win, html);
  win.document.write(html);
  win.document.close();
  win.focus();

  let fallbackCloseTimer: number | undefined;

  const clearFallbackCloseTimer = (): void => {
    if (fallbackCloseTimer !== undefined) {
      window.clearTimeout(fallbackCloseTimer);
      fallbackCloseTimer = undefined;
    }
  };

  const closeTicketWindow = (): void => {
    clearFallbackCloseTimer();

    try {
      if (!win.closed) {
        win.close();
      }
    } catch {
      clearFallbackCloseTimer();
    }
  };

  try {
    win.addEventListener(
      'afterprint',
      () => {
        window.setTimeout(closeTicketWindow, 250);
      },
      { once: true },
    );

    win.addEventListener('pagehide', closeTicketWindow, { once: true });
  } catch {
    clearFallbackCloseTimer();
  }

  void waitForTicketReady(win).then(() => {
    window.setTimeout(() => {
      try {
        win.print();
      } catch {
        closeTicketWindow();
      }
    }, 100);

    fallbackCloseTimer = window.setTimeout(closeTicketWindow, 3000);
  });
}

export function getTicketWindowFeatures(html?: string): string {
  const is58mm = html?.includes('data-ticket-width="58mm"') ?? false;
  const width = is58mm ? 340 : 420;
  const height = is58mm ? 680 : 760;

  return `width=${width},height=${height}`;
}

function resizePrintWindow(win: Window, html: string): void {
  const is58mm = html.includes('data-ticket-width="58mm"');
  const width = is58mm ? 340 : 420;
  const height = is58mm ? 680 : 760;

  try {
    win.resizeTo(width, height);
  } catch {
    // Some browsers block resizeTo for script-opened windows.
  }
}

async function waitForTicketReady(win: Window): Promise<void> {
  const doc = win.document;
  const fontReady = 'fonts' in doc
    ? (doc as Document & { fonts: { ready: Promise<unknown> } }).fonts.ready.catch(() => undefined)
    : Promise.resolve();

  const imageReady = Array.from(doc.images).map((image) => {
    if (image.complete) {
      return Promise.resolve();
    }

    return new Promise<void>((resolve) => {
      image.addEventListener('load', () => resolve(), { once: true });
      image.addEventListener('error', () => resolve(), { once: true });
    });
  });

  await Promise.race([
    Promise.all([fontReady, ...imageReady]),
    new Promise((resolve) => window.setTimeout(resolve, 900)),
  ]);
}
