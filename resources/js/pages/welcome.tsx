import { Form, Head } from '@inertiajs/react';
import AnalyzeSurveyController from '@/actions/App/Http/Controllers/AnalyzeSurveyController';
import { Button } from '@/components/ui/button';

type Survey = {
    id: number;
    answer: string;
};

type WelcomeProps = {
    surveys: Survey[];
    analyses?: SurveyAnalysis[];
    summary?: AnalysisSummary;
};

type AnalysisRow = {
    label: string;
    value: string;
    certainty: string;
};

type SurveyAnalysis = {
    id: number;
    rows: AnalysisRow[];
    model: string;
    inputTokens: number;
    outputTokens: number;
    costUsd: number;
    costJpy: number;
};

type AnalysisSummary = {
    inputTokens: number;
    outputTokens: number;
    costUsd: number;
    costJpy: number;
};

const analysisColumns = [
    '主題',
    '発話意図',
    '感情',
    '改善への具体性',
    '講演との関連性',
    '期待とのズレ',
    '入力ミス・訂正への言及',
] as const;

const chartColumns = ['主題', '発話意図', '感情', '改善への具体性'] as const;

const chartColors = [
    '#2563eb',
    '#f97316',
    '#10b981',
    '#a855f7',
    '#eab308',
    '#ec4899',
    '#06b6d4',
    '#ef4444',
    '#84cc16',
] as const;

function distributionFor(
    analyses: SurveyAnalysis[],
    label: string,
): Array<{ value: string; count: number }> {
    const counts = new Map<string, number>();

    analyses.forEach((analysis) => {
        const value = analysis.rows.find((row) => row.label === label)?.value;

        if (value) {
            counts.set(value, (counts.get(value) ?? 0) + 1);
        }
    });

    return Array.from(counts, ([value, count]) => ({ value, count })).sort(
        (a, b) => b.count - a.count,
    );
}

function DistributionChart({
    title,
    analyses,
}: {
    title: string;
    analyses: SurveyAnalysis[];
}) {
    const distribution = distributionFor(analyses, title);
    const total = distribution.reduce((sum, item) => sum + item.count, 0);
    let offset = 0;
    const gradient = distribution
        .map((item, index) => {
            const start = offset;
            offset += (item.count / total) * 100;

            return `${chartColors[index % chartColors.length]} ${start}% ${offset}%`;
        })
        .join(', ');

    return (
        <section className="flex flex-col gap-4 rounded-lg border p-4">
            <h2 className="text-sm font-medium">{title}</h2>
            <div className="flex flex-col items-center gap-5">
                <div
                    className="relative size-36 shrink-0 rounded-full"
                    style={{
                        backgroundImage: `conic-gradient(${gradient})`,
                    }}
                    role="img"
                    aria-label={`${title}の構成比`}
                >
                    <div className="absolute inset-7 flex flex-col items-center justify-center rounded-full bg-background">
                        <span className="text-2xl font-semibold">{total}</span>
                        <span className="text-xs text-muted-foreground">
                            件
                        </span>
                    </div>
                </div>

                <div className="flex w-full flex-col gap-2">
                    {distribution.map((item, index) => (
                        <div
                            key={item.value}
                            className="flex items-start gap-2 text-xs"
                        >
                            <span
                                className="mt-1 size-2.5 shrink-0 rounded-full"
                                style={{
                                    backgroundColor:
                                        chartColors[index % chartColors.length],
                                }}
                            />
                            <span className="min-w-0 flex-1 leading-5">
                                {item.value}
                            </span>
                            <span className="shrink-0 font-medium">
                                {item.count}件
                            </span>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

export default function Welcome({
    surveys,
    analyses = [],
    summary,
}: WelcomeProps) {
    const surveysById = new Map(surveys.map((survey) => [survey.id, survey]));

    return (
        <>
            <Head title="アンケート分析" />

            <main className="min-h-screen bg-background px-4 py-10 text-foreground sm:px-6">
                <section
                    className={`mx-auto flex flex-col gap-8 ${analyses.length > 0 ? 'max-w-[100rem]' : 'max-w-3xl'}`}
                >
                    <header className="flex items-start justify-between gap-4">
                        <div className="flex flex-col gap-2">
                            <h1 className="text-3xl font-semibold">
                                アンケート分析
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                アンケート回答 {surveys.length}件
                            </p>
                        </div>

                        <Form action={AnalyzeSurveyController()}>
                            {({ processing }) => (
                                <Button type="submit" disabled={processing}>
                                    {processing ? '分析中…' : '分析'}
                                </Button>
                            )}
                        </Form>
                    </header>

                    {summary && (
                        <dl className="grid grid-cols-2 gap-4 rounded-lg border p-4 text-sm sm:grid-cols-4">
                            <div>
                                <dt className="text-muted-foreground">
                                    分析件数
                                </dt>
                                <dd className="mt-1 font-medium">
                                    {analyses.length}件
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    入力トークン
                                </dt>
                                <dd className="mt-1 font-medium">
                                    {summary.inputTokens.toLocaleString()}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    出力トークン
                                </dt>
                                <dd className="mt-1 font-medium">
                                    {summary.outputTokens.toLocaleString()}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    合計コスト
                                </dt>
                                <dd className="mt-1 font-medium">
                                    ${summary.costUsd.toFixed(8)} / ¥
                                    {summary.costJpy.toFixed(4)}
                                </dd>
                            </div>
                        </dl>
                    )}

                    {analyses.length > 0 ? (
                        <div className="grid items-start gap-6 lg:grid-cols-[38rem_minmax(0,1fr)]">
                            <aside className="max-h-[calc(100vh-12rem)] overflow-y-auto lg:sticky lg:top-6">
                                <div className="mb-4">
                                    <h2 className="font-semibold">集計</h2>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {analyses.length}件の分析結果
                                    </p>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    {chartColumns.map((column) => (
                                        <DistributionChart
                                            key={column}
                                            title={column}
                                            analyses={analyses}
                                        />
                                    ))}
                                </div>
                            </aside>

                            <div className="max-h-[calc(100vh-12rem)] overflow-auto rounded-lg border">
                                <table className="min-w-[100rem] border-collapse text-left text-sm">
                                    <thead className="sticky top-0 z-10 bg-muted">
                                        <tr>
                                            <th className="w-14 border-r px-3 py-3 font-medium">
                                                ID
                                            </th>
                                            <th className="w-80 border-r px-4 py-3 font-medium">
                                                回答
                                            </th>
                                            {analysisColumns.map((column) => (
                                                <th
                                                    key={column}
                                                    className="min-w-40 border-r px-4 py-3 font-medium last:border-r-0"
                                                >
                                                    {column}
                                                </th>
                                            ))}
                                            <th className="min-w-44 px-4 py-3 font-medium">
                                                利用量・コスト
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {analyses.map((analysis) => {
                                            const survey = surveysById.get(
                                                analysis.id,
                                            );
                                            const rowsByLabel = new Map(
                                                analysis.rows.map((row) => [
                                                    row.label,
                                                    row,
                                                ]),
                                            );

                                            return (
                                                <tr
                                                    key={analysis.id}
                                                    className="border-t align-top"
                                                >
                                                    <td className="border-r px-3 py-4 text-muted-foreground">
                                                        {analysis.id}
                                                    </td>
                                                    <td className="border-r px-4 py-4 leading-6">
                                                        {survey?.answer}
                                                    </td>
                                                    {analysisColumns.map(
                                                        (column) => {
                                                            const row =
                                                                rowsByLabel.get(
                                                                    column,
                                                                );

                                                            return (
                                                                <td
                                                                    key={column}
                                                                    className="border-r px-4 py-4 last:border-r-0"
                                                                >
                                                                    <div className="font-medium">
                                                                        {
                                                                            row?.value
                                                                        }
                                                                    </div>
                                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                                        {
                                                                            row?.certainty
                                                                        }
                                                                    </div>
                                                                </td>
                                                            );
                                                        },
                                                    )}
                                                    <td className="px-4 py-4 text-xs leading-5 text-muted-foreground">
                                                        <div>
                                                            {analysis.model}
                                                        </div>
                                                        <div>
                                                            入力{' '}
                                                            {
                                                                analysis.inputTokens
                                                            }{' '}
                                                            / 出力{' '}
                                                            {
                                                                analysis.outputTokens
                                                            }
                                                        </div>
                                                        <div>
                                                            $
                                                            {analysis.costUsd.toFixed(
                                                                8,
                                                            )}{' '}
                                                            / ¥
                                                            {analysis.costJpy.toFixed(
                                                                4,
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ) : (
                        <div className="overflow-hidden rounded-lg border">
                            {surveys.map((survey) => (
                                <article
                                    key={survey.id}
                                    className="grid grid-cols-[3rem_1fr] border-b last:border-b-0"
                                >
                                    <div className="border-r px-3 py-4 text-sm text-muted-foreground">
                                        {survey.id}
                                    </div>
                                    <p className="px-4 py-4 text-sm leading-6">
                                        {survey.answer}
                                    </p>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </main>
        </>
    );
}
