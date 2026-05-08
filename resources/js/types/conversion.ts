export type RecentConversion = {
    uuid: string;
    url: string;
    label: string;
    createdAt: string | null;
};

export type AttemptSummary = {
    id: number;
    n: number;
    label: string;
    status: 'pending' | 'running' | 'ready' | 'failed';
    displayStatus: 'pending' | 'running' | 'ready' | 'failed' | 'partial';
    selected: boolean;
    url: string;
};

export type AttemptDetail = AttemptSummary & {
    displayFilename: string | null;
    inputBytes: number | null;
    inputPixels: number | null;
    inputPreview: string | null;
    pptxBytes: number | null;
    pdfBytes: number | null;
    failureCode: string | null;
    failureMessage: string | null;
    downloads: {
        pptx: string | null;
        pdf: string | null;
        pdfInline: string | null;
    };
    createdAt: string | null;
    startedAt: string | null;
    finishedAt: string | null;
};

export type ConversionPageProps = {
    conversion: {
        uuid: string;
        url: string;
        totalBytes: number;
    };
    ttlHours: number;
    recentConversions: RecentConversion[];
    attempts: AttemptSummary[];
    selectedAttempt: AttemptDetail;
};
