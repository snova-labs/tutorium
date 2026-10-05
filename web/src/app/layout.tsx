import type { Metadata } from "next";
import { Toaster } from "@/components/ui/sonner";
import "./globals.css";

export const metadata: Metadata = {
  title: { default: "Tutorium", template: "%s · Tutorium" },
  description: "Attendance, grades and reports for tutoring academies.",
};

// System font stacks rather than next/font/google: the build does not depend on reaching a font
// host, and the product's stack (Inter, JetBrains Mono) is used where installed.
export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" className="h-full">
      <body className="min-h-full flex flex-col">
        {children}
        <Toaster position="top-right" />
      </body>
    </html>
  );
}
