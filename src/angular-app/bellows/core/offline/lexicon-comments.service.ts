import * as angular from 'angular';

import { ApiService } from '../api/api.service';
import { JsonRpcCallback } from '../api/json-rpc.service';
import { CommentsOfflineCacheService } from './comments-offline-cache.service';

class Comment {
  id: string;
  entryRef: string;
  regarding: any;
  replies: any[];
  score: number;
  status: string;
}
class CommentItems {
  currentEntry: Comment[];
  currentEntryFiltered: Comment[];
  all: Comment[];

  constructor() {
    this.currentEntry = [];
    this.currentEntryFiltered = [];
    this.all = [];
  }
}
interface CountFields { [fieldName: string]: number; }
class CountCurrentEntry {
  total: number;
  fields: CountFields;

  constructor() {
    this.total = 0;
    this.fields = {};
  }
}
class CommentCounts {
  currentEntry: CountCurrentEntry;
  byEntry: { [entryId: string]: number; };
  userPlusOne: { [commentId: string]: number; };

  constructor() {
    this.currentEntry = new CountCurrentEntry();
    this.byEntry = {};
    this.userPlusOne = {};
  }
}

export class Comments {
  items: CommentItems;
  counts: CommentCounts;

  constructor() {
    this.items = new CommentItems();
    this.counts = new CommentCounts();
  }
}

export class LexiconCommentService {
  comments: Comments;

  static $inject: string[] = ['apiService', 'commentsOfflineCache', '$filter'];
  constructor(private api: ApiService, private offlineCache: CommentsOfflineCacheService,
              private $filter: angular.IFilterService) {
    this.comments = new Comments();
  }

  /**
   * This should be called whenever the entry context changes (to update the comments and comment counts)
   */
  loadEntryComments(entryId: string): void {
    this.comments.counts.currentEntry.total = 0;
    this.comments.counts.currentEntry.fields = {};
    this.comments.items.currentEntry.length = 0;
    for (const comment of this.comments.items.all) {
      const fieldName = comment.regarding.field;
      if (comment.entryRef === entryId) {
        if (fieldName && angular.isUndefined(this.comments.counts.currentEntry.fields[fieldName])) {
          this.comments.counts.currentEntry.fields[fieldName] = 0;
        }

        this.comments.items.currentEntry.push(comment);

        // update the appropriate count for this field and update the total count
        if (comment.status !== 'resolved') {
          if (fieldName) {
            this.comments.counts.currentEntry.fields[fieldName]++;
          }

          this.comments.counts.currentEntry.total++;
        }
      }
    }
  }

  /**
   * this should be called whenever new data is received
   */
  updateGlobalCommentCounts(): void {
    for (const comment of this.comments.items.all) {
      // add counts to global entry comment counts
      if (angular.isUndefined(this.comments.counts.byEntry[comment.entryRef])) {
        this.comments.counts.byEntry[comment.entryRef] = 0;
      }

      if (comment.status !== 'resolved') {
        this.comments.counts.byEntry[comment.entryRef]++;
      }
    }
  }

  getFieldCommentCount(fieldName: string): number {
    if (angular.isDefined(this.comments.counts.currentEntry.fields[fieldName])) {
      return this.comments.counts.currentEntry.fields[fieldName];
    }

    return 0;
  }

  getEntryCommentCount(entryId: string): number {
    if (angular.isDefined(this.comments.counts.byEntry[entryId])) {
      return this.comments.counts.byEntry[entryId];
    }

    return 0;
  }

  removeCommentFromLists(commentId: string, replyId: string): void {
    if (replyId) {
      // just delete the replyId but don't delete the entire comment
      LexiconCommentService.deleteCommentInList(commentId, replyId, this.comments.items.all);
      LexiconCommentService.deleteCommentInList(commentId, replyId, this.comments.items.currentEntry);
      LexiconCommentService.deleteCommentInList(commentId, replyId, this.comments.items.currentEntryFiltered);
    } else {
      // delete the entire comment
      this.offlineCache.deleteComment(commentId).then(() => {
        if (angular.isDefined(this) && angular.isDefined(this.comments)) {
          LexiconCommentService.deleteCommentInList(commentId, null, this.comments.items.all);
          LexiconCommentService.deleteCommentInList(commentId, null, this.comments.items.currentEntry);
          LexiconCommentService.deleteCommentInList(commentId, null, this.comments.items.currentEntryFiltered);
        }
      });
    }
  }

  refreshFilteredComments(filter: any): void {
    this.comments.items.currentEntryFiltered.length = 0;
    const comments = this.$filter('filter')(this.comments.items.currentEntry, filter.byText);
    const arr = this.comments.items.currentEntryFiltered;
    arr.push.apply(arr, this.$filter('filter')(comments, filter.byStatus));
  }

  update(commentData: any, callback: JsonRpcCallback): void {
    this.api.call('lex_comment_update', [commentData], callback);
  }

  updateReply(commentId: string, reply: string, callback: JsonRpcCallback): void {
    const comment = this.getCurrentEntryComment(commentId);
    comment.replies.push(reply);
    this.api.call('lex_commentReply_update', [commentId, reply], callback);
  }

  remove(commentId: string, callback: JsonRpcCallback): void {
    this.api.call('lex_comment_delete', [commentId], callback);
  }

  deleteReply(commentId: string, replyId: string, callback: JsonRpcCallback): void {
    this.api.call('lex_commentReply_delete', [commentId, replyId], callback);
  }

  plusOne(commentId: string, callback: JsonRpcCallback): void {
    const comment = this.getCurrentEntryComment(commentId);
    comment.score++;
    this.comments.counts.userPlusOne[commentId] = 1;
    this.api.call('lex_comment_plusOne', [commentId], callback);
  }

  updateStatus(commentId: string, status: string, callback: JsonRpcCallback): void {
    const comment = this.getCurrentEntryComment(commentId);
    comment.status = status;
    this.api.call('lex_comment_updateStatus', [commentId, status], callback);
  }

  private static deleteCommentInList(commentId: string, replyId: string, commentsList: any): void {
    let deleteComment = true;
    if (replyId) {
      deleteComment = false;
    }

    for (let i = commentsList.length - 1; i >= 0; i--) {
      const comment = commentsList[i];
      if (deleteComment) {
        if (comment.id === commentId) {
          commentsList.splice(i, 1);
        }
      } else {
        // delete Reply
        for (let j = comment.replies.length - 1; j >= 0; j--) {
          const reply = comment.replies[j];
          if (reply.id === replyId) {
            comment.replies.splice(j, 1);
          }
        }
      }
    }
  }

  private getCurrentEntryComment(id: string) {
    const comments = this.comments.items.currentEntry;
    for (const comment of comments) {
      if (comment.id === id) {
        return comment;
      }
    }
  }

}
