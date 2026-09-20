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
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="min-w-[100rem] border-collapse text-left text-sm">
                                <thead className="bg-muted">
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
                                                                    {row?.value}
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
                                                    <div>{analysis.model}</div>
                                                    <div>
                                                        入力{' '}
                                                        {analysis.inputTokens} /
                                                        出力{' '}
                                                        {analysis.outputTokens}
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
