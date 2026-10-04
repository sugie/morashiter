import type { Metadata } from "next";
import SupportReviewApp from "./support-review-app";

const title = "支援主体向けレビュー | RightsRelay";
const description = "最小共有パケットを端末内だけで検証・集計する支援主体向けプロトタイプです。";

export const metadata: Metadata = {
  title,
  description,
  openGraph: {
    title,
    description,
    images: [],
  },
  twitter: {
    card: "summary",
    title,
    description,
    images: [],
  },
};

export default function SupportReviewPage() {
  return <SupportReviewApp />;
}
