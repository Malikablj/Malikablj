import { ChevronLeft } from 'lucide-react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/components/layout/PageHeader';
import { emptyProjectValues, hasClientErrors, ProjectFormFields, useProjectForm } from '@/components/project/ProjectForm';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState, ErrorState, InlineAlert, PageSkeleton } from '@/components/ui/Feedback';
import { useToast } from '@/components/ui/Toast';
import { canCreateProject } from '@/domain/permissions';
import { errorMessage, isAppError } from '@/domain/errors';
import { useAction, useLookups, useWorkflows } from '@/hooks/queries';
import { useUser } from '@/hooks/useAuth';
import { createProject } from '@/services/api/projects';
import type { ProjectType } from '@/types';

export default function ProjectCreatePage() {
  const user = useUser();
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const toast = useToast();
  const lookups = useLookups();
  const workflows = useWorkflows();
  const initialType = (params.get('type') as ProjectType | null) ?? 'subcont';
  const form = useProjectForm({
    ...emptyProjectValues(initialType),
    salesPicId: user.role === 'admin_sales' ? user.id : '',
    npdPicId: user.role === 'npd_staff' ? user.id : '',
  });
  const create = useAction(createProject);

  if (!canCreateProject(user))
    return (
      <Card>
        <EmptyState title="Akses dibatasi" description="Role Anda tidak dapat membuat project. Hubungi Admin, Admin Sales, atau NPD Staff." />
      </Card>
    );
  if (lookups.isLoading || workflows.isLoading) return <PageSkeleton />;
  if (lookups.error || workflows.error) return <ErrorState error={lookups.error ?? workflows.error} onRetry={() => { lookups.refetch(); workflows.refetch(); }} />;

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    form.setSubmitted(true);
    if (hasClientErrors(form.values)) {
      toast.error('Lengkapi data wajib', 'Periksa kolom yang ditandai merah.');
      return;
    }
    create.mutate(form.values, {
      onSuccess: (project) => {
        toast.success('Project dibuat', `${project.code} · workflow ${project.type === 'subcont' ? 'Subcont' : 'New Mold'} otomatis dibuat.`);
        navigate(`/projects/${project.code}`, { replace: true });
      },
      onError: (err) => {
        form.onError(err);
        toast.fromError(err, 'Project gagal dibuat');
      },
    });
  };

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader
        eyebrow={
          <Link to="/projects" className="inline-flex items-center gap-1 hover:text-ink">
            <ChevronLeft className="size-4" /> Project
          </Link>
        }
        title="Project Baru"
        description="Project ID dibuat otomatis (NPD-YYYY-XXX). Workflow dipilih otomatis sesuai Project Type."
      />
      <form onSubmit={submit} noValidate>
        <Card className="p-5 md:p-8">
          {create.error && !(isAppError(create.error) && create.error.fieldErrors) ? (
            <div className="mb-6">
              <InlineAlert tone="red" title="Project gagal dibuat">
                {errorMessage(create.error)}
              </InlineAlert>
            </div>
          ) : null}
          <ProjectFormFields form={form} users={lookups.data!.users} customers={lookups.data!.customers} workflows={workflows.data!} mode="create" />
        </Card>
        <div className="sticky bottom-0 z-10 -mx-4 mt-4 flex justify-end gap-2 border-t border-line bg-canvas/90 px-4 py-3 backdrop-blur md:static md:mx-0 md:border-0 md:bg-transparent md:p-0 max-md:pb-[max(12px,env(safe-area-inset-bottom))]">
          <Button variant="secondary" onClick={() => navigate(-1)} disabled={create.isPending}>
            Batal
          </Button>
          <Button type="submit" variant="primary" loading={create.isPending}>
            Buat Project
          </Button>
        </div>
      </form>
    </div>
  );
}
