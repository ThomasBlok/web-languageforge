import * as angular from 'angular';

export class Crumb {
  label: string;
  href?: string;
}

class CrumbStore {
  [id: string]: Crumb[];
}

export class BreadcrumbService {
  private crumbStore = new CrumbStore();

  updateCrumb(id: string, index: number, update: Crumb): void {
    this.ensureIdIsRegistered(id);
    let crumb = this.crumbStore[id][index];
    for (let property in update) {
      if (update.hasOwnProperty(property)) {
        crumb[property] = update[property];
      }
    }
  }

  set(id: string, crumbs: Crumb[]): void {
    this.ensureIdIsRegistered(id);
    this.crumbStore[id] = crumbs;
  }

  get(id: string): Crumb[] {
    this.ensureIdIsRegistered(id);
    return this.crumbStore[id];
  }

  setLastIndex(id: string, index: number): void {
    this.ensureIdIsRegistered(id);
    if (this.crumbStore[id].length > 1 + index) {
      this.crumbStore[id].splice(1 + index, this.crumbStore[id].length - index);
    }
  }

  private ensureIdIsRegistered(id: string): void {
    if (angular.isUndefined(this.crumbStore[id])) {
      this.crumbStore[id] = [];
    }
  }

}
