import type { Metadata } from "next";
import MorashiterApp from "./morashiter-app";

export const metadata: Metadata = {
  title: "Morashiter | 企業インシデント公表情報一覧",
  description:
    "個人情報漏えいインシデントの公式情報、対象可能性、今できることを安全に整理するプロトタイプです。",
};

export default function Home() {
  return <MorashiterApp />;
}
