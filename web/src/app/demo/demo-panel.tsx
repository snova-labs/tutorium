"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState, useTransition } from "react";

import { runDemo } from "@/app/demo/actions";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { isBusy, needsEmailedCode, stateLabel, type DemoStatus } from "@/lib/demo";

export function DemoPanel({ status }: { status: DemoStatus }) {
  const router = useRouter();
  const [password, setPassword] = useState("");
  const [result, setResult] = useState<{ ok: boolean; message?: string } | null>(null);
  const [pending, start] = useTransition();
  const busy = isBusy(status.state);

  // While the server works, look again every few seconds.
  useEffect(() => {
    if (!busy) {
      return;
    }

    const timer = setInterval(() => router.refresh(), 4000);

    return () => clearInterval(timer);
  }, [busy, router]);

  const run = (action: "load" | "remove") =>
    start(async () => {
      if (action === "remove" && !window.confirm("Remove every demo academy and everything in them?")) {
        return;
      }

      const outcome = await runDemo(action, password);
      setResult(outcome);
      router.refresh();
    });

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>Demo academies</CardTitle>
          <CardDescription>
            Four example academies, one of each kind the platform serves, each with a term already under way. Every name is
            invented and every address is on a reserved domain, so nothing reaches a real person.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center gap-2 text-sm">
            <Badge variant={status.state === "failed" ? "outline" : "secondary"}>{status.state}</Badge>
            <span role="status">{status.message && busy ? status.message : stateLabel(status.state)}</span>
          </div>
          {status.state === "failed" && status.message && (
            <Alert variant="warn">
              <AlertDescription>{status.message}</AlertDescription>
            </Alert>
          )}

          <form
            className="space-y-3"
            onSubmit={(event) => {
              event.preventDefault();
              run("load");
            }}
          >
            <div className="max-w-sm space-y-1.5">
              <Label htmlFor="password">Demo password</Label>
              <Input
                id="password"
                type="password"
                autoComplete="off"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                required
              />
              <p className="text-xs text-muted-foreground">It is also the password of every demo account.</p>
            </div>
            {/* A refusal stays until the next try; "started" only matters while it is running. */}
            {result && (!result.ok || busy) && <p className={`text-sm ${result.ok ? "text-ok" : "text-bad"}`}>{result.message}</p>}
            <div className="flex flex-wrap gap-2">
              <Button type="submit" disabled={pending || busy || password === ""}>
                {status.state === "ready" ? "Reload demo data" : "Load demo data"}
              </Button>
              <Button
                type="button"
                variant="outline"
                disabled={pending || busy || password === "" || status.state === "none"}
                onClick={() => run("remove")}
              >
                Remove demo data
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      {status.state === "ready" && (
        <>
          <p className="text-sm">
            Sign in at{" "}
            <Link href="/sign-in" className="font-medium underline underline-offset-4">
              the sign-in page
            </Link>{" "}
            with any account below and the demo password. Owners, managers and accountants confirm with an emailed code, which
            on staging is in the API log (<code>make staging codes</code>).
          </p>
          {status.academies.map((academy) => (
            <Card key={academy.academy}>
              <CardHeader>
                <CardTitle>{academy.academy}</CardTitle>
                <CardDescription>{academy.kind}</CardDescription>
              </CardHeader>
              <CardContent className="px-0">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="pl-6">Who</TableHead>
                      <TableHead>Email</TableHead>
                      <TableHead className="pr-6">Role</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {academy.accounts.map((account) => (
                      <TableRow key={account.email}>
                        <TableCell className="pl-6">{account.name}</TableCell>
                        <TableCell className="font-mono text-xs">{account.email}</TableCell>
                        <TableCell className="pr-6">
                          {account.role}
                          {needsEmailedCode(account.role) && <span className="text-xs text-muted-foreground"> · emailed code</span>}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </CardContent>
            </Card>
          ))}
        </>
      )}
    </div>
  );
}
